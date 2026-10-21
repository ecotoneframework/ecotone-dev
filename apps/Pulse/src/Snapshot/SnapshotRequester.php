<?php

declare(strict_types=1);

namespace App\Snapshot;

use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
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
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function request(string $service, bool $force): RequestResult
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $claimed = false;
        $this->store->update($service, function (ServiceRecord $record) use ($force, $now, &$claimed): ServiceRecord {
            $claimed = $force || $record->needsRequest($now);

            return $claimed ? $record->requested($now) : $record;
        });
        if (! $claimed) {
            return RequestResult::NotNeeded;
        }

        try {
            $this->distributedBus->sendMessage($service, self::ROUTING_KEY, '{}', MediaType::APPLICATION_JSON, replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the snapshot request to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the snapshot request could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($service, fn (ServiceRecord $record) => $record->requestFailed($failure));

            return RequestResult::Failed;
        }

        return RequestResult::Sent;
    }
}
