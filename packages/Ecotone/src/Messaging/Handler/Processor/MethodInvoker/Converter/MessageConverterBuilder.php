<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class MessageParameterConverterBuilder
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class MessageConverterBuilder implements ParameterConverterBuilder
{
    private string $parameterName;

    /**
     * MessageParameterConverterBuilder constructor.
     * @param string $parameterName
     */
    private function __construct(string $parameterName)
    {
        $this->parameterName = $parameterName;
    }

    /**
     * @param string $parameterName
     * @return MessageConverterBuilder
     */
    public static function create(string $parameterName): self
    {
        return new self($parameterName);
    }

    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService, InterfaceToCall $interfaceToCall, InterfaceParameter $interfaceParameter): ParameterConverter
    {
        return MessageConverter::create();
    }

    /**
     * @inheritDoc
     */
    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }
}
