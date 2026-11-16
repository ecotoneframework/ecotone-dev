<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\Router\RouteSelector;

/**
 * licence Apache-2.0
 */
final class RecordedRouteSelector implements RouteSelector
{
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
