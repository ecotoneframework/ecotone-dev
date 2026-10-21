<?php

declare(strict_types=1);

namespace App\DeadLetter;

use App\Settings\PulseSettings;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\RequestResult;
use App\Snapshot\ServiceScope;
use App\Snapshot\SnapshotReceiver;
use App\Snapshot\SnapshotStore;
use App\Snapshot\TenantMetadata;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class DeadLetterActionRequester
{
    private const FOLLOW_UP_SECONDS = 5;

    public function __construct(
        private DistributedBus $distributedBus,
        private SnapshotStore $store,
        private TenantMetadata $tenantMetadata,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function send(ServiceScope $scope, DeadLetterAction $action): RequestResult
    {
        $service = $scope->service;
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($scope, fn ($record) => $action->isReplay() ? $record->requested($now, $this->settings->replyTimeoutSeconds)->withFollowUpDueAt($now->modify('+' . self::FOLLOW_UP_SECONDS . ' seconds')) : $record->requested($now, $this->settings->replyTimeoutSeconds));

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage($service, $action->routingKey, $action->payload, MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId, ...$this->tenantMetadata->of($scope)], replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the dead letter action to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the dead letter action could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($scope, fn ($record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, SnapshotReceiver::class);
    }
}
