<?php

declare(strict_types=1);

namespace App\Projection;

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

final readonly class ProjectionActionRequester
{
    public const ROUTING_KEYS = [Rebuild::REBUILD => 'ecotone.pulse.projections.rebuild', Rebuild::INITIALIZATION => 'ecotone.pulse.projections.initialize', Rebuild::BACKFILL => 'ecotone.pulse.projections.backfill'];

    public function __construct(
        private DistributedBus $distributedBus,
        private SnapshotStore $store,
        private TenantMetadata $tenantMetadata,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function send(ServiceScope $scope, string $action, string $projection): RequestResult
    {
        $service = $scope->service;
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($scope, fn ($record) => $record->requested($now, $this->settings->replyTimeoutSeconds)->forgettingRefusalOf(self::ROUTING_KEYS[$action]));

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage($service, self::ROUTING_KEYS[$action], json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId, ...$this->tenantMetadata->of($scope)], replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the projection {action} to service "{service}": {message}', ['action' => $action, 'service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the projection %s could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1), $action);
            $this->store->update($scope, fn ($record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, SnapshotReceiver::class);
    }
}
