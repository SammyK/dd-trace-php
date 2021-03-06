<?php

namespace DDTrace\Tests\Integration\Integrations\Predis;

use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Tests\Integration\Common\IntegrationTestCase;
use DDTrace\Tests\Integration\Common\SpanAssertion;
use Predis\Response\Status;


final class PredisTest extends IntegrationTestCase
{
    private $host = 'redis_integration';
    private $port = '6379';

    public static function setUpBeforeClass()
    {
        PredisIntegration::load();
    }

    protected function setUp()
    {
        parent::setUp();
    }

    public function testPredisIntegrationCreatesSpans()
    {
        $traces = $this->inTestScope('redis.test', function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $value = 'bar';

            $client->set('foo', $value);

            $this->assertEquals($client->get('foo'), $value);
        });

        $this->assertCount(1, $traces);
        $trace = $traces[0];

        $this->assertContainsOnlyInstancesOf("\OpenTracing\Span", $trace);
        $this->assertGreaterThan(2, count($trace)); # two Redis operations -> at least 2 spans
    }

    public function testPredisConstruct()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $this->assertNotNull($client);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('Predis.Client.__construct', 'redis', 'cache', 'Predis.Client.__construct')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisConnect()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->connect();
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.connect', 'redis', 'cache', 'Predis.Client.connect')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisSetCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('foo', 'value');
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'cache', 'SET foo value')
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'SET foo value',
                    'redis.args_length' => '3',
                ])),
        ]);
    }

    public function testPredisGetCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::exists('Predis.Client.executeCommand'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'cache', 'GET key')
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'GET key',
                    'redis.args_length' => '2',
                ])),
        ]);
    }

    public function testPredisRawCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->executeRaw(["SET", "key", "value"]);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeRaw', 'redis', 'cache', 'SET key value')
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'SET key value',
                    'redis.args_length' => '3',
                ])),
        ]);
    }

    public function testPredisPipeline()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            list($responsePing, $responseFlush) = $client->pipeline(function ($pipe) {
                $pipe->ping();
                $pipe->flushdb();
            });
            $this->assertInstanceOf(Status::class, $responsePing);
            $this->assertInstanceOf(Status::class, $responseFlush);
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Pipeline.executePipeline', 'redis', 'cache', 'Predis.Pipeline.executePipeline')
                ->withExactTags([
                    'redis.pipeline_length' => '2'
                ]),
        ]);
    }

    private function baseTags()
    {
        return [
            'out.host' => $this->host,
            'out.port' => $this->port,
        ];
    }
}
