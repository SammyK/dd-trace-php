<?php

use DDTrace\Tags;
use DDTrace\FinallyPolyfill;
use OpenTracing\GlobalTracer;

function foo()
{
    return 42.0 + lcg_value();
}

class FinallyPolyfillBench
{
    public function benchDestructor()
    {
        dd_trace('foo', function () {
            $scope = GlobalTracer::get()->startActiveSpan('foo');
            $final = new FinallyPolyfill(function () use ($scope) {
                $scope->close();
            });
            $span = $scope->getSpan();
            $span->setTag(Tags\SERVICE_NAME, 'FOO');

            try {
                return foo();
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            }
        });
        foo();
    }

    public function benchTempVariable()
    {
        dd_trace('foo', function () {
            $scope = GlobalTracer::get()->startActiveSpan('foo');
            $span = $scope->getSpan();
            $span->setTag(Tags\SERVICE_NAME, 'FOO');

            $thrownException = null;
            try {
                return foo();
            } catch (\Exception $e) {
                $span->setError($e);
                $thrownException = $e;
            }
            $scope->close();
            if ($thrownException) {
                throw $thrownException;
            }
        });
        foo();
    }

    public function benchHelperFunction()
    {
        dd_trace('foo', function () {
            $scope = GlobalTracer::get()->startActiveSpan('foo');
            $span = $scope->getSpan();
            $span->setTag(Tags\SERVICE_NAME, 'FOO');

            finallyPolyfillHelper(
                function () {
                    return foo();
                },
                function (\Exception $e) use ($span) {
                    $span->setError($e);
                },
                function () use ($scope) {
                    $scope->close();
                }
            );
        });
        foo();
    }
}

function finallyPolyfillHelper(\Closure $try, \Closure $catch, \Closure $finally)
{
    $return = null;
    try {
        $return = $try();
    } catch (\Exception $e) {
        $catch($e);
        $finally();
        throw $e;
    }
    $finally();
    return $return;
}
