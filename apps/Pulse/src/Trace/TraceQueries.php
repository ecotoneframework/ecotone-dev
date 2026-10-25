<?php

declare(strict_types=1);

namespace App\Trace;

use App\Settings\PulseSettings;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\RequestResult;
use App\Snapshot\ServiceScope;
use App\Snapshot\TenantMetadata;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class TraceQueries
{
    public const QUERY_ID_HEADER = 'ecotone_pulse_query_id';

    public function __construct(
        private DistributedBus $distributedBus,
        private QueryStore $store,
        private TenantMetadata $tenantMetadata,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private PulseSettings $settings,
        private LoggerInterface $logger,
        private ReplyAwaiter $awaiter,
    ) {
    }

    public function ask(ServiceScope $scope, TraceQuery $query): RequestResult
    {
        $service = $scope->service;
        $now = $this->now();
        $queryId = bin2hex(random_bytes(8));
        $claimed = false;
        $this->store->update($scope, $query->key(), function (QueryRecord $record) use ($now, $queryId, $query, &$claimed): QueryRecord {
            $claimed = $record->needsRequest($now, $query->freshnessSeconds);

            return $claimed ? $record->requested($queryId, $now) : $record;
        });

        if (! $claimed) {
            return RequestResult::notNeeded();
        }

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage($service, $query->routingKey, $query->json(), MediaType::APPLICATION_JSON, metadata: [self::QUERY_ID_HEADER => $queryId, MessageHeaders::TRACING_ID => $tracingId, ...$this->tenantMetadata->of($scope)], replyRoutingKey: QueryResultReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the trace query to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the trace query could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($scope, $query->key(), fn (QueryRecord $record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, QueryResultReceiver::class);
    }

    public function answerOf(ServiceScope $scope, TraceQuery $query): TraceAnswer
    {
        $record = $this->store->read($scope, $query->key());

        return new TraceAnswer($scope->key(), $record->stateAt($this->now(), $this->settings->replyTimeoutSeconds), $record->result, $record->refusal ?? $record->failure);
    }

    /**
     * @param list<ServiceScope> $scopes
     * @return list<TraceAnswer>
     */
    public function askAll(array $scopes, TraceQuery $query): array
    {
        $this->awaiter->await(array_map(fn (ServiceScope $scope) => $this->ask($scope, $query), $scopes));

        return array_map(fn (ServiceScope $scope) => $this->answerOf($scope, $query), $scopes);
    }

    /**
     * @param list<TraceAnswer> $answers
     */
    public static function anyWaiting(array $answers): bool
    {
        foreach ($answers as $answer) {
            if ($answer->state === TraceAnswerState::Waiting) {
                return true;
            }
        }

        return false;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
