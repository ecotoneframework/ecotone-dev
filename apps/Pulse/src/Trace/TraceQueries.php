<?php

declare(strict_types=1);

namespace App\Trace;

use App\Settings\PulseSettings;
use DateTimeImmutable;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Gateway\DistributedBus;
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
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private PulseSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function ask(string $service, TraceQuery $query): TraceAnswer
    {
        $now = $this->now();
        $queryId = bin2hex(random_bytes(8));
        $claimed = false;
        $this->store->update($service, $query->key(), function (QueryRecord $record) use ($now, $queryId, &$claimed): QueryRecord {
            $claimed = $record->needsRequest($now);

            return $claimed ? $record->requested($queryId, $now) : $record;
        });

        if ($claimed) {
            try {
                $this->distributedBus->sendMessage($service, $query->routingKey, $query->json(), MediaType::APPLICATION_JSON, metadata: [self::QUERY_ID_HEADER => $queryId], replyRoutingKey: QueryResultReceiver::ROUTING_KEY);
            } catch (Throwable $exception) {
                $this->logger->error('Pulse could not send the trace query to service "{service}": {message}', ['service' => $service, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $failure = sprintf('%s: the trace query could not be sent. The full error is in the Pulse web log.', substr(strrchr('\\' . $exception::class, '\\'), 1));
                $this->store->update($service, $query->key(), fn (QueryRecord $record) => $record->requestFailed($failure));
            }
        }

        return $this->answerOf($service, $query);
    }

    public function answerOf(string $service, TraceQuery $query): TraceAnswer
    {
        $record = $this->store->read($service, $query->key());

        return new TraceAnswer($service, $record->stateAt($this->now(), $this->settings->replyTimeoutSeconds), $record->result, $record->failure);
    }

    /**
     * @param list<string> $services
     * @return list<TraceAnswer>
     */
    public function askAll(array $services, TraceQuery $query): array
    {
        $answers = array_map(fn (string $service) => $this->ask($service, $query), $services);
        $deadline = hrtime(true) + $this->settings->renderWaitSeconds * 1_000_000_000;
        while (hrtime(true) < $deadline && self::anyWaiting($answers)) {
            usleep(100_000);
            $answers = array_map(fn (string $service) => $this->answerOf($service, $query), $services);
        }

        return $answers;
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
