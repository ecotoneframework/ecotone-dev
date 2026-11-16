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
        $this->tracer->recordStep('route ' . $this->reasonOf($message) . ($routes === [] ? 'to no channel' : 'to ' . implode(', ', $routes)) . ($this->routeHeader === '' ? '' : ', named by the header ' . $this->routeHeader . ' of the message'));

        return $routes;
    }

    private function reasonOf(Message $message): string
    {
        if ($this->routingKeyHeader === '') {
            return '';
        }
        $headers = $message->getHeaders();
        if ($headers->containsKey($this->routingKeyHeader) && is_string($headers->get($this->routingKeyHeader))) {
            return 'by name ' . $headers->get($this->routingKeyHeader) . ' ';
        }

        return 'by class ' . ExecutionRecorder::typeOf($message->getPayload()) . ' ';
    }

    public function __toString(): string
    {
        return (string) $this->routeSelector;
    }
}
