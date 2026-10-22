<?php

declare(strict_types=1);

namespace App\Trace;

use App\Snapshot\FailureReply;
use DateTimeImmutable;
use Ecotone\Api\Attribute\InternalHandler;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class QueryResultReceiver
{
    public const ROUTING_KEY = 'ecotone.pulse.query_result_received';

    public function __construct(
        private QueryStore $store,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[InternalHandler(self::ROUTING_KEY)]
    public function receive(string $result): void
    {
        $decoded = json_decode($result, true);
        $queryId = is_array($decoded) ? ($decoded['queryId'] ?? null) : null;
        $asked = is_string($queryId) ? $this->store->whoAskedWith($queryId) : null;
        if ($asked === null) {
            $this->logger->warning('Pulse ignored a trace query reply it cannot attribute to a query it asked.', ['reply' => mb_substr($result, 0, 200)]);

            return;
        }

        [$scope, $queryKey] = $asked;
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($scope, $queryKey, function (QueryRecord $record) use ($queryId, $decoded, $now): QueryRecord {
            if ($record->queryId !== $queryId) {
                return $record;
            }

            return FailureReply::isOne($decoded) ? $record->refused(FailureReply::of($decoded)->message, $now) : $record->received($decoded, $now);
        });
    }
}
