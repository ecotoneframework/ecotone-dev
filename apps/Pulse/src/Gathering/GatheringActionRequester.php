<?php

declare(strict_types=1);

namespace App\Gathering;

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

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 */
final readonly class GatheringActionRequester
{
    public const ROUTING_KEYS = ['release' => 'ecotone.pulse.gathering.release', 'discard' => 'ecotone.pulse.gathering.discard'];

    public function __construct(
        private DistributedBus $distributedBus,
        private SnapshotStore $store,
        private TenantMetadata $tenantMetadata,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function send(ServiceScope $scope, string $action, string $groupId): RequestResult
    {
        $service = $scope->service;
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($scope, fn ($record) => $record->requested($now, $this->settings->replyTimeoutSeconds)->forgettingRefusalOf(self::ROUTING_KEYS[$action]));

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage(targetServiceName: $service, routingKey: self::ROUTING_KEYS[$action], payload: json_encode(['groupId' => $groupId], JSON_THROW_ON_ERROR), sourceMediaType: MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId, ...$this->tenantMetadata->of($scope)], replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the gathering {action} to service "{service}": {message}', ['action' => $action, 'service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the gathering %s could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1), $action);
            $this->store->update($scope, fn ($record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, SnapshotReceiver::class);
    }
}
