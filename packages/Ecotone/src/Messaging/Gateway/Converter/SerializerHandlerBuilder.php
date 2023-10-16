<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Gateway\Converter;

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
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;

/**
 * Class SerializerHandlerBuilder
 * @package Ecotone\Messaging\Gateway\Converter
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class SerializerHandlerBuilder extends InputOutputMessageHandlerBuilder implements CompilableBuilder
{
    private function __construct(private string $methodName)
    {
    }

    public static function createFromPHP(): self
    {
        return new self('convertFromPHP');
    }

    public static function createToPHP(): self
    {
        return new self('convertToPHP');
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(Serializer::class, $this->methodName);
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        if (! $builder->has(SerializerHandler::class)) {
            $builder->register(SerializerHandler::class, new Definition(SerializerHandler::class, [
                new Reference(ConversionService::REFERENCE_NAME),
            ]));
        }
        $interfaceToCall = $builder->getInterfaceToCall(new InterfaceToCallReference(SerializerHandler::class, $this->methodName));
        return ServiceActivatorBuilder::create(SerializerHandler::class, $interfaceToCall)
            ->compile($builder);
    }
}
