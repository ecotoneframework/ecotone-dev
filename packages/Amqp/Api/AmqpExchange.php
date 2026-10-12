<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Interop\Amqp\AmqpTopic as EnqueueExchange;

/**
 * licence Apache-2.0
 */
class AmqpExchange
{
    private const DEFAULT_DURABILITY = true;

    /**
     * @var EnqueueExchange
     */
    private $enqueueExchange;
    /**
     * @var bool
     */
    private $withDurability = self::DEFAULT_DURABILITY;

    private function __construct(string $exchangeName, string $exchangeType)
    {
        $this->enqueueExchange = new \Interop\Amqp\Impl\AmqpTopic($exchangeName);
        $this->enqueueExchange->setType($exchangeType);
    }

    public function isHeadersExchange(): bool
    {
        return $this->enqueueExchange->getType() === EnqueueExchange::TYPE_HEADERS;
    }

    public function withDurability(bool $isDurable): self
    {
        $this->withDurability = $isDurable;

        return $this;
    }

    public function withAutoDeletion(): self
    {
        $this->enqueueExchange->addFlag(EnqueueExchange::FLAG_AUTODELETE);

        return $this;
    }

    /**
     * @param $value
     */
    public function withArgument(string $name, $value): self
    {
        $this->enqueueExchange->setArgument($name, $value);

        return $this;
    }

    public function getExchangeName(): string
    {
        return $this->enqueueExchange->getTopicName();
    }

    public function toEnqueueExchange(): EnqueueExchange
    {
        $amqpTopic = clone $this->enqueueExchange;

        if ($this->withDurability) {
            $amqpTopic->addFlag(EnqueueExchange::FLAG_DURABLE);
        }

        return $amqpTopic;
    }

    public static function createDirectExchange(string $exchangeName): self
    {
        return new self($exchangeName, EnqueueExchange::TYPE_DIRECT);
    }

    public static function createFanoutExchange(string $exchangeName): self
    {
        return new self($exchangeName, EnqueueExchange::TYPE_FANOUT);
    }

    public static function createTopicExchange(string $exchangeName): self
    {
        return new self($exchangeName, EnqueueExchange::TYPE_TOPIC);
    }

    public static function createHeadersExchange(string $exchangeName): self
    {
        return new self($exchangeName, EnqueueExchange::TYPE_HEADERS);
    }
}
