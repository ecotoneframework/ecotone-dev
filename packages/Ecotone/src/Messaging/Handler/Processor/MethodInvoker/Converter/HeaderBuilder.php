<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceParameterReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

/**
 * Class HeaderBuilder
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class HeaderBuilder implements ParameterConverterBuilder
{
    private function __construct(private string $parameterName, private string $headerName, private bool $isRequired)
    {
    }

    public static function create(string $parameterName, string $headerName): self
    {
        return new self($parameterName, $headerName, true);
    }

    /**
     * @param string $parameterName
     * @param string $headerName
     * @return HeaderBuilder
     */
    public static function createOptional(string $parameterName, string $headerName): self
    {
        return new self($parameterName, $headerName, false);
    }

    /**
     * @inheritDoc
     */
    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    public function compile(MessagingContainerBuilder $builder, InterfaceToCall $interfaceToCall): Definition
    {
        return new Definition(HeaderConverter::class, [
            InterfaceParameterReference::fromInstance($interfaceToCall, $this->parameterName),
            $this->headerName,
            $this->isRequired,
            new Reference(ConversionService::REFERENCE_NAME),
        ]);
    }
}
