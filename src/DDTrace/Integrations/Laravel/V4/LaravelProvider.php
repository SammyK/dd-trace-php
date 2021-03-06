<?php

namespace DDTrace\Integrations\Laravel\V4;

use DDTrace;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Encoders\Json;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Types;
use DDTrace\Transport\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenTracing\GlobalTracer;

/**
 * DataDog Laravel 4.2 tracing provider. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then load the provider in config/app.php:
 *
 *     'providers' => array_merge(include(base_path('modules/system/providers.php')), [
 *        // 'Illuminate\Html\HtmlServiceProvider', // Example
 *
 *        'DDTrace\Integrations\Laravel\V4\LaravelProvider',
 *        'System\ServiceProvider',
 *   ]),
 */
class LaravelProvider extends ServiceProvider
{
    /** @inheritdoc */
    public function register()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return;
        }

        if (getenv('APP_ENV') != 'dd_testing' && php_sapi_name() == 'cli') {
            return;
        }

        // Creates a tracer with default transport and default encoders
        $tracer = new Tracer(new Http(new Json()));

        // Sets a global tracer (singleton). Also store it in the Laravel
        // container for easy Laravel-specific use.
        GlobalTracer::set($tracer);
        $this->app->instance(Tracer::class, $tracer);
    }

    /** @inheritdoc */
    public function boot()
    {
        $tracer = GlobalTracer::get();

        $startSpanOptions = StartSpanOptionsFactory::createForWebRequest(
            $tracer,
            [
                'start_time' => DDTrace\Time\now(),
            ],
            $this->app->make('request')->header()
        );

        // Create a span that starts from when Laravel first boots (public/index.php)
        $scope = $tracer->startActiveSpan('laravel.request', $startSpanOptions);
        $requestSpan = $scope->getSpan();
        $requestSpan->setTag(Tags\SERVICE_NAME, $this->getAppName());
        $requestSpan->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);

        // Name the scope when the route matches
        $this->app['events']->listen('router.matched', function () use ($scope) {
            $args = func_get_args();
            list($route, $request) = $args;
            $span = $scope->getSpan();

            $span->setTag(Tags\RESOURCE_NAME, $route->getActionName() . ' ' . Route::currentRouteName());
            $span->setTag('laravel.route.name', $route->getName());
            $span->setTag('laravel.route.action', $route->getActionName());
            $span->setTag(Tags\HTTP_METHOD, $request->method());
            $span->setTag(Tags\HTTP_URL, $request->url());
        });

        // Enable extension integrations
        EloquentIntegration::load();
        if (class_exists('Memcached')) {
            MemcachedIntegration::load();
        }

        PDOIntegration::load();

        if (class_exists('Predis\Client')) {
            PredisIntegration::load();
        }

        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });

        dd_trace('Illuminate\Foundation\Application', 'handle', function () use ($requestSpan) {
            $args = func_get_args();

            $response = call_user_func_array([$this, 'handle'], $args);
            $requestSpan->setTag(Tags\HTTP_STATUS_CODE, $response->getStatusCode());

            return $response;
        });

        dd_trace('Illuminate\Routing\Route', 'run', function () {
            $scope = LaravelProvider::buildBaseScope('laravel.action', $this->uri);
            $span = $scope->getSpan();

            try {
                return $this->run();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        dd_trace('Illuminate\View\View', 'render', function () {
            $args = func_get_args();
            $scope = LaravelProvider::buildBaseScope('laravel.view.render', $this->view);
            $span = $scope->getSpan();

            try {
                return call_user_func_array([$this, 'render'], $args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        dd_trace('Illuminate\Events\Dispatcher', 'fire', function () {
            $args = func_get_args();
            $scope = LaravelProvider::buildBaseScope('laravel.event.handle', $args[0]);
            $span = $scope->getSpan();

            try {
                return call_user_func_array([$this, 'fire'], $args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });
    }

    /**
     * Starts a basic scope object with the common info required by all the resources.
     *
     * @param string $operation
     * @param string $resource
     * @return \OpenTracing\Scope
     */
    public static function buildBaseScope($operation, $resource)
    {
        $scope = GlobalTracer::get()->startActiveSpan($operation);
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);
        $span->setTag(Tags\SERVICE_NAME, self::getAppName());
        $span->setTag(Tags\RESOURCE_NAME, $resource);

        return $scope;
    }

    /**
     * Returns the configurable app name.
     *
     * @return array|false|\Illuminate\Config\Repository|mixed|null|string
     */
    private static function getAppName()
    {
        $name = null;

        if (getenv('ddtrace_app_name')) {
            $name = getenv('ddtrace_app_name');
        } elseif (is_callable('config')) {
            $name = config('app.name');
        }

        return empty($name) ? 'laravel' : $name;
    }
}
