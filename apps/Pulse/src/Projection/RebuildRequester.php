<?php

declare(strict_types=1);

namespace App\Projection;

use App\Settings\PulseSettings;
use App\Snapshot\ReplyAwaiter;
use App\Snapshot\RequestResult;
use App\Snapshot\SnapshotReceiver;
use App\Snapshot\SnapshotStore;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class RebuildRequester
{
    public const ROUTING_KEY = 'ecotone.pulse.projections.rebuild';

    public function __construct(
        private DistributedBus $distributedBus,
        private SnapshotStore $store,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function send(string $service, string $projection): RequestResult
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($service, fn ($record) => $record->requested($now, $this->settings->replyTimeoutSeconds));

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage($service, self::ROUTING_KEY, json_encode(['projection' => $projection], JSON_THROW_ON_ERROR), MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the projection rebuild to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the projection rebuild could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($service, fn ($record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, SnapshotReceiver::class);
    }
}
