<?php

declare(strict_types=1);

namespace App\DeadLetter;

use App\Settings\PulseSettings;
use App\Snapshot\RequestResult;
use App\Snapshot\SnapshotReceiver;
use App\Snapshot\SnapshotStore;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
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
        private PulseSettings $settings,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function send(string $service, DeadLetterAction $action): RequestResult
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->store->update($service, fn ($record) => $action->isReplay() ? $record->requested($now, $this->settings->replyTimeoutSeconds)->withFollowUpDueAt($now->modify('+' . self::FOLLOW_UP_SECONDS . ' seconds')) : $record->requested($now, $this->settings->replyTimeoutSeconds));

        try {
            $this->distributedBus->sendMessage($service, $action->routingKey, $action->payload, MediaType::APPLICATION_JSON, replyRoutingKey: SnapshotReceiver::ROUTING_KEY);
        } catch (Throwable $exception) {
            $this->logger->error('Pulse could not send the dead letter action to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = sprintf('%s: the dead letter action could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
            $this->store->update($service, fn ($record) => $record->requestFailed($failure));

            return RequestResult::Failed;
        }

        return RequestResult::Sent;
    }
}
