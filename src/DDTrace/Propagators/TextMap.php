<?php

namespace DDTrace\Propagators;

use DDTrace\Propagator;
use DDTrace\SpanContext;

final class TextMap implements Propagator
{
    const DEFAULT_BAGGAGE_HEADER_PREFIX = 'ot-baggage-';
    const DEFAULT_TRACE_ID_HEADER = 'x-datadog-trace-id';
    const DEFAULT_PARENT_ID_HEADER = 'x-datadog-parent-id';

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContext $spanContext, &$carrier)
    {
        $carrier[self::DEFAULT_TRACE_ID_HEADER] = $spanContext->getTraceId();
        $carrier[self::DEFAULT_PARENT_ID_HEADER] = $spanContext->getSpanId();

        foreach ($spanContext as $key => $value) {
            $carrier[self::DEFAULT_BAGGAGE_HEADER_PREFIX . $key] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        $traceId = null;
        $spanId = null;
        $baggageItems = [];

        foreach ($carrier as $key => $value) {
            if ($key === self::DEFAULT_TRACE_ID_HEADER) {
                $traceId = $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === self::DEFAULT_PARENT_ID_HEADER) {
                $spanId = $this->extractStringOrFirstArrayElement($value);
            } elseif (strpos($key, self::DEFAULT_BAGGAGE_HEADER_PREFIX) === 0) {
                $baggageItems[substr($key, strlen(self::DEFAULT_BAGGAGE_HEADER_PREFIX))] = $value;
            }
        }

        if ($traceId === null || $spanId === null) {
            return null;
        }

        return new SpanContext($traceId, $spanId, null, $baggageItems);
    }

    /**
     * A utility function to mitigate differences between how headers are provided by various web frameworks.
     * E.g. in both the cases that follow, this method would return 'application/json':
     *   1) as array of values: ['content-type' => ['application/json']]
     *   2) as string value: ['content-type' => 'application/json']
     *
     * @param array|string $value
     * @return string|null
     */
    private function extractStringOrFirstArrayElement($value)
    {
        if (is_array($value) && count($value) > 0) {
            return $value[0];
        } elseif (is_string($value)) {
            return $value;
        }
        return null;
    }
}
