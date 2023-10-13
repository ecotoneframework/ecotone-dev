<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Logger;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MessageConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Class LoggingHandlerBuilder
 * @package Ecotone\Messaging\Handler\Logger
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class LoggingHandlerBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters
{
    public const LOGGER_REFERENCE = 'logger';
    public const LOG_FULL_MESSAGE = false;

    private string $logLevel = LogLevel::DEBUG;
    private bool $logFullMessage = self::LOG_FULL_MESSAGE;
    /**
     * @var ParameterConverterBuilder[]
     */
    private array $methodParameterConverters = [];

    /**
     * LoggingHandlerBuilder constructor.
     * @param bool $isBefore
     */
    private function __construct(private bool $isBefore)
    {
    }

    /**
     * @return LoggingHandlerBuilder
     */
    public static function createForBefore(): self
    {
        return new self(true);
    }

    public static function createForAfter(): self
    {
        return new self(false);
    }

    /**
     * @param string $logLevel
     * @return LoggingHandlerBuilder
     */
    public function withLogLevel(string $logLevel): self
    {
        $this->logLevel = $logLevel;

        return $this;
    }

    /**
     * @param bool $logFullMessage
     * @return LoggingHandlerBuilder
     */
    public function withLogFullMessage(bool $logFullMessage): self
    {
        $this->logFullMessage = $logFullMessage;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        $this->methodParameterConverters = $methodParameterConverterBuilders;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverters;
    }

    /**
     * @inheritDoc
     */
    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        if (!$builder->has(LoggingInterceptor::class)) {
            $builder->register(LoggingInterceptor::class, [
                new Definition(LoggingService::class, [Reference::to(ConversionService::REFERENCE_NAME), Reference::to(self::LOGGER_REFERENCE)]),
            ]);
        }
        return ServiceActivatorBuilder::create(
            LoggingInterceptor::class,
            $builder->getInterfaceToCall(new InterfaceToCallReference(LoggingInterceptor::class, $this->getMethodName()))
        )
            ->withPassThroughMessageOnVoidInterface(true)
            ->withOutputMessageChannel($this->getOutputMessageChannelName())
            ->withMethodParameterConverters($this->methodParameterConverters)
            ->compile($builder);
    }


    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [$interfaceToCallRegistry->getFor(LoggingInterceptor::class, $this->getMethodName())];
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(LoggingInterceptor::class, $this->getMethodName());
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        return [self::LOGGER_REFERENCE];
    }

    /**
     * @return string
     */
    private function getMethodName(): string
    {
        return $this->isBefore ? 'logBefore' : 'logAfter';
    }
}
