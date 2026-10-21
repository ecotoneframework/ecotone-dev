<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use Closure;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Messaging\DestinationDoesNotExistException;
use Ecotone\Api\Messaging\MessageHeaders;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class SnapshotRequester
{
    public const ROUTING_KEY = 'ecotone.pulse.snapshot';

    public function __construct(
        private DistributedBus $distributedBus,
        private SnapshotStore $store,
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function request(string $service, bool $force): RequestResult
    {
        return $this->send($service, static fn (ServiceRecord $record, DateTimeImmutable $now): bool => $force || $record->needsRequest($now));
    }

    public function refresh(string $service): RequestResult
    {
        return $this->send($service, fn (ServiceRecord $record, DateTimeImmutable $now): bool => $record->needsRefresh($now, $this->settings->replyTimeoutSeconds));
    }

    /**
     * @param Closure(ServiceRecord, DateTimeImmutable): bool $shouldRequest
     */
    private function send(string $service, Closure $shouldRequest): RequestResult
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $claimed = false;
        $this->store->update($service, function (ServiceRecord $record) use ($shouldRequest, $now, &$claimed): ServiceRecord {
            $claimed = $shouldRequest($record, $now);

            return $claimed ? $record->requested($now, $this->settings->replyTimeoutSeconds) : $record;
        });
        if (! $claimed) {
            return RequestResult::notNeeded();
        }

        $tracingId = ReplyAwaiter::newTracingId();
        try {
            $this->distributedBus->sendMessage($service, self::ROUTING_KEY, '{}', MediaType::APPLICATION_JSON, metadata: [MessageHeaders::TRACING_ID => $tracingId], replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (DestinationDoesNotExistException $exception) {
            $this->logger->warning('Pulse did not send the snapshot request to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage()]);
            $failure = sprintf("Channel missing: '%s' does not exist on connection '%s', so nothing was sent. The service creates it when it starts consuming; Pulse asks again on the next view.", $exception->destination, $exception->connection);
            $this->store->update($service, fn (ServiceRecord $record) => $record->channelMissing($exception->destination, $failure));

            return RequestResult::failed();
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the snapshot request to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the snapshot request could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($service, fn (ServiceRecord $record) => $record->requestFailed($failure));

            return RequestResult::failed();
        }

        return RequestResult::sent($tracingId, SnapshotReceiver::class);
    }
}
