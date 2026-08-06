<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\ParameterType;
use Ecotone\Messaging\Scheduling\Duration;
use Interop\Queue\Destination;
use Interop\Queue\Exception\Exception;
use Interop\Queue\Exception\InvalidDestinationException;
use Interop\Queue\Exception\InvalidMessageException;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * licence MIT
 * code comes from https://github.com/php-enqueue/dbal
 */
class DbalProducer implements Producer
{
    private const BATCH_INSERT_CHUNK_SIZE = 250;

    private const PARAMETERIZED_COLUMN_BINDINGS = [
        'id' => ParameterType::STRING,
        'published_at' => ParameterType::INTEGER,
        'body' => ParameterType::STRING,
        'headers' => ParameterType::STRING,
        'properties' => ParameterType::STRING,
        'priority' => ParameterType::INTEGER,
        'queue' => ParameterType::STRING,
        'delayed_until' => ParameterType::INTEGER,
        'time_to_live' => ParameterType::INTEGER,
    ];

    private const CONSTANT_COLUMNS = 'redelivered, delivery_id, redeliver_after';
    private const CONSTANT_COLUMN_VALUES = 'FALSE, NULL, NULL';

    /**
     * @var int|null
     */
    private $priority;

    /**
     * @var int|float|null
     */
    private $deliveryDelay;

    /**
     * @var int|float|null
     */
    private $timeToLive;

    /**
     * @var DbalContext
     */
    private $context;

    public function __construct(DbalContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param DbalDestination $destination
     * @param DbalMessage     $message
     */
    public function send(Destination $destination, Message $message): void
    {
        $this->sendBatch($destination, [$message]);
    }

    /**
     * @param DbalMessage[] $messages
     */
    public function sendBatch(Destination $destination, array $messages): void
    {
        InvalidDestinationException::assertDestinationInstanceOf($destination, DbalDestination::class);

        if ([] === $messages) {
            return;
        }

        $records = [];
        foreach ($messages as $message) {
            InvalidMessageException::assertMessageInstanceOf($message, DbalMessage::class);
            $this->applyProducerDefaults($message);
            $records[] = $this->createRecord($destination, $message);
        }

        try {
            $rowsAffected = 0;
            foreach (array_chunk($records, self::BATCH_INSERT_CHUNK_SIZE) as $recordsChunk) {
                $rowsAffected += $this->insertRecords($recordsChunk);
            }
        } catch (\Exception $e) {
            throw new Exception('The transport fails to send the message due to some internal error.', 0, $e);
        }

        if (count($records) !== $rowsAffected) {
            throw new Exception('The batch was not enqueued. Dbal did not confirm that all records are inserted.');
        }
    }

    private function insertRecords(array $records): int
    {
        $parameterizedColumns = array_keys(self::PARAMETERIZED_COLUMN_BINDINGS);
        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($parameterizedColumns), '?')) . ', ' . self::CONSTANT_COLUMN_VALUES . ')';

        $sql = sprintf(
            'INSERT INTO %s (%s, %s) VALUES %s',
            $this->context->getTableName(),
            implode(', ', $parameterizedColumns),
            self::CONSTANT_COLUMNS,
            implode(', ', array_fill(0, count($records), $rowPlaceholders)),
        );

        $parameters = [];
        $types = [];
        foreach ($records as $record) {
            foreach (self::PARAMETERIZED_COLUMN_BINDINGS as $column => $bindingType) {
                $parameters[] = $record[$column];
                $types[] = $record[$column] === null ? ParameterType::NULL : $bindingType;
            }
        }

        return (int) $this->context->getDbalConnection()->executeStatement($sql, $parameters, $types);
    }

    private function applyProducerDefaults(DbalMessage $message): void
    {
        if (null !== $this->priority && null === $message->getPriority()) {
            $message->setPriority($this->priority);
        }
        if (null !== $this->deliveryDelay && null === $message->getDeliveryDelay()) {
            $message->setDeliveryDelay($this->deliveryDelay);
        }
        if (null !== $this->timeToLive && null === $message->getTimeToLive()) {
            $message->setTimeToLive($this->timeToLive);
        }
    }

    private function createRecord(DbalDestination $destination, DbalMessage $message): array
    {
        $publishedAt = $message->getPublishedAt()
            ?? (int) ($this->context->getClock()->now()->unixTime()->toFloat() * 10_000); // x 10_000 ?!?!!??

        $record = [
            'id' => Uuid::v7()->toRfc4122(),
            'published_at' => $publishedAt,
            'body' => $message->getBody(),
            'headers' => JSON::encode($message->getHeaders()),
            'properties' => JSON::encode($message->getProperties()),
            'priority' => -1 * $message->getPriority(),
            'queue' => $destination->getQueueName(),
            'redelivered' => false,
            'delivery_id' => null,
            'redeliver_after' => null,
            'delayed_until' => null,
            'time_to_live' => null,
        ];

        $delay = $message->getDeliveryDelay();
        if ($delay) {
            if (! is_int($delay)) {
                throw new LogicException(sprintf('Delay must be integer but got: "%s"', is_object($delay) ? get_class($delay) : gettype($delay)));
            }

            if ($delay <= 0) {
                throw new LogicException(sprintf('Delay must be positive integer but got: "%s"', $delay));
            }

            $record['delayed_until'] = $this->context->getClock()->now()->add(Duration::milliseconds($delay))->unixTime()->inSeconds();
        }

        $timeToLive = $message->getTimeToLive();
        if ($timeToLive) {
            if (! is_int($timeToLive)) {
                throw new LogicException(sprintf('TimeToLive must be integer but got: "%s"', is_object($timeToLive) ? get_class($timeToLive) : gettype($timeToLive)));
            }

            if ($timeToLive <= 0) {
                throw new LogicException(sprintf('TimeToLive must be positive integer but got: "%s"', $timeToLive));
            }

            $record['time_to_live'] = $this->context->getClock()->now()->add(Duration::milliseconds($timeToLive))->unixTime()->inSeconds();
        }

        return $record;
    }

    public function setDeliveryDelay(?int $deliveryDelay = null): Producer
    {
        $this->deliveryDelay = $deliveryDelay;

        return $this;
    }

    public function getDeliveryDelay(): ?int
    {
        return $this->deliveryDelay;
    }

    public function setPriority(?int $priority = null): Producer
    {
        $this->priority = $priority;

        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setTimeToLive(?int $timeToLive = null): Producer
    {
        $this->timeToLive = $timeToLive;

        return $this;
    }

    public function getTimeToLive(): ?int
    {
        return $this->timeToLive;
    }
}
