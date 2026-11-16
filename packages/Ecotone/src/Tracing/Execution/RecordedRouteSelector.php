<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Router\RouteSelector;

/**
 * licence Apache-2.0
 */
final class RecordedRouteSelector implements RouteSelector
{
    public const SERVICE_MAP = 'service map';

    public function __construct(private RouteSelector $routeSelector, private string $routingKeyHeader, private string $routeHeader, private ExecutionRecordingTracer $tracer)
    {
    }

    public function route(Message $message): array
    {
        $routes = $this->routeSelector->route($message);
        $reason = $this->reasonOf($message);
        $this->tracer->recordStep('route to ' . ($routes === [] ? 'no channel' : implode(', ', $routes)), hints: $reason === '' ? [] : [ExecutionHint::critical(ExecutionHint::CHOSEN_BY, $reason)]);

        return $routes;
    }

    private function reasonOf(Message $message): string
    {
        if ($this->routeHeader === self::SERVICE_MAP) {
            $headers = $message->getHeaders();
            $header = fn (string $name): string => $headers->containsKey($name) ? (string) $headers->get($name) : '?';
            $type = $header(DistributedBusHeader::DISTRIBUTED_PAYLOAD_TYPE);

            return self::SERVICE_MAP . ', ' . ($type === 'event' ? 'event ' . $header(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) : $type . ' to ' . $header(DistributedBusHeader::DISTRIBUTED_TARGET_SERVICE_NAME));
        }
        if ($this->routeHeader !== '') {
            return 'header ' . $this->routeHeader;
        }
        if ($this->routingKeyHeader === '') {
            return '';
        }
        $headers = $message->getHeaders();
        if ($headers->containsKey($this->routingKeyHeader) && is_string($headers->get($this->routingKeyHeader))) {
            return 'name ' . $headers->get($this->routingKeyHeader);
        }

        return 'class ' . ExecutionRecorder::typeOf($message->getPayload());
    }

    public function __toString(): string
    {
        return (string) $this->routeSelector;
    }
}
