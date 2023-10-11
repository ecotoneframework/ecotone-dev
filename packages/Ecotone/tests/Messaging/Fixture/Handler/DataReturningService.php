<?php

namespace Test\Ecotone\Messaging\Fixture\Handler;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Endpoint\PollingConsumer\RejectMessageException;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\MessageBuilder;
use InvalidArgumentException;
use Throwable;

class DataReturningService implements DefinedObject
{
    private $data;
    /**
     * @var bool
     */
    private $asAMessage;
    /**
     * @var array
     */
    private $headers;

    private ?Throwable $exception;

    public function __construct($data, bool $asAMessage, array $headers, ?Throwable $exception)
    {
        $this->data = $data;
        $this->asAMessage = $asAMessage;
        $this->headers = $headers;
        $this->exception = $exception;
    }

    public static function createServiceActivator($dataToReturn): MessageHandler
    {
        return self::createServiceActivatorBuilder($dataToReturn)->build(InMemoryChannelResolver::createEmpty(), InMemoryReferenceSearchService::createEmpty());
    }

    public static function createExceptionalServiceActivator(): MessageHandler
    {
        return (ServiceActivatorBuilder::createWithDirectReference(new self('', false, [], new InvalidArgumentException('error during handling')), 'handle'))->build(InMemoryChannelResolver::createEmpty(), InMemoryReferenceSearchService::createEmpty());
    }

    public static function createServiceActivatorWithReturnMessage($payload, array $headers): MessageHandler
    {
        return self::createServiceActivatorBuilderWithReturnMessage($payload, $headers)->build(InMemoryChannelResolver::createEmpty(), InMemoryReferenceSearchService::createEmpty());
    }

    public static function createServiceActivatorBuilder($dataToReturn): ServiceActivatorBuilder
    {
        return ServiceActivatorBuilder::createWithDirectReference(new self($dataToReturn, false, [], null), 'handle');
    }

    public static function createExceptionalServiceActivatorBuilder(): ServiceActivatorBuilder
    {
        return (ServiceActivatorBuilder::createWithDirectReference(new self('', false, [], new InvalidArgumentException('error during handling')), 'handle'));
    }

    public static function createServiceActivatorBuilderWithReturnMessage($payload, array $headers): ServiceActivatorBuilder
    {
        return ServiceActivatorBuilder::createWithDirectReference(new self($payload, true, $headers, null), 'handle');
    }

    public static function createServiceActivatorBuilderWithRejectException(): ServiceActivatorBuilder
    {
        return ServiceActivatorBuilder::createWithDirectReference(new self('', true, [], new RejectMessageException('rejecting message')), 'handle');
    }

    public function handle(Message $message)
    {
        if ($this->exception) {
            throw new $this->exception();
        }

        if ($this->asAMessage) {
            return MessageBuilder::fromMessage($message)
                        ->setMultipleHeaders($this->headers)
                        ->setPayload($this->data)
                        ->build();
        }

        return $this->data;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->data,
            $this->asAMessage,
            $this->headers,
            $this->exception ? new Definition(Throwable::class, [\serialize($this->exception)], [self::class, 'unserialize']) : null,
        ]);
    }

    /**
     * @internal
     */
    public static function unserialize(string $serializedException): Throwable
    {
        return unserialize($serializedException);
    }
}
