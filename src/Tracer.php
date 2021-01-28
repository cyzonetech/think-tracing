<?php

namespace think\tracing;

use InvalidArgumentException;
use Jaeger\Reporter\RemoteReporter;
use Jaeger\Sampler\ConstSampler;
use Jaeger\Sender\UdpSender;
use Jaeger\Thrift\Agent\AgentClient;
use think\helper\Arr;
use think\Manager;
use Thrift\Protocol\TCompactProtocol;
use Thrift\Transport\TCurlClient;
use Zipkin\Endpoint;
use Zipkin\Reporters\Http;
use Zipkin\Samplers\BinarySampler;
use Zipkin\TracingBuilder;

/**
 * Class Tracer
 * @package think\tracing
 * @mixin \ZipkinOpenTracing\Tracer
 */
class Tracer extends Manager
{

    /**
     * 获取配置
     * @param null|string $name 名称
     * @param mixed $default 默认值
     * @return mixed
     */
    public function getConfig(string $name = null, $default = null)
    {
        if (!is_null($name)) {
            return $this->app->config->get('tracing.' . $name, $default);
        }

        return $this->app->config->get('tracing');
    }

    protected function resolveConfig(string $name)
    {
        return $this->getTracerConfig($name);
    }

    protected function resolveParams($name): array
    {
        return array_merge([$name], parent::resolveParams($name));
    }

    protected function createJaegerDriver($name, $config)
    {
        $endpoint  = parse_url(Arr::get($config, 'endpoint'));
        $transport = new TCurlClient(
            Arr::get($endpoint, 'host'),
            Arr::get($endpoint, 'port', 80),
            Arr::get($endpoint, 'path'),
            Arr::get($endpoint, 'scheme')
        );
        $protocol  = new TCompactProtocol($transport);
        $client    = new AgentClient($protocol);
        $sender    = new UdpSender($client, $config['max_buffer_length'] ?? 64000);
        $reporter  = new RemoteReporter($sender);

        $sampler = new ConstSampler();

        return new \Jaeger\Tracer(
            $name,
            $reporter,
            $sampler
        );
    }

    protected function createZipkinDriver($name, $config)
    {
        $endpoint = Endpoint::create($name, gethostbyname(gethostname()));

        $reporter = new Http([
            'endpoint_url' => Arr::get($config, 'endpoint'),
        ]);
        $sampler  = BinarySampler::createAsAlwaysSample();
        $tracing  = TracingBuilder::create()
            ->havingLocalEndpoint($endpoint)
            ->havingTraceId128bits(Arr::get($config, '128bit', false))
            ->havingSampler($sampler)
            ->havingReporter($reporter)
            ->build();

        return new \ZipkinOpenTracing\Tracer($tracing);
    }

    /**
     * 获取驱动配置
     * @param string $tracer
     * @param string|null $name
     * @param null $default
     * @return array
     */
    public function getTracerConfig(string $tracer, string $name = null, $default = null)
    {
        if ($config = $this->getConfig("tracers.{$tracer}")) {
            return Arr::get($config, $name, $default);
        }

        throw new InvalidArgumentException("Tracer [$tracer] not found.");
    }

    protected function resolveType(string $name)
    {
        return $this->getTracerConfig($name, 'type', 'zipkin');
    }

    /**
     * 默认驱动
     * @return string|null
     */
    public function getDefaultDriver()
    {
        return $this->getConfig('default');
    }
}
