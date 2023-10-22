<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter;

use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Gateway\GatewayParameterConverterBuilder;
use Ecotone\Messaging\Handler\InterfaceParameter;

/**
 * Class HeaderToMessageConverterBuilder
 * @package Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class GatewayHeaderBuilder implements GatewayParameterConverterBuilder
{
    private string $parameterName;
    private string $headerName;

    /**
     * HeaderMessageParameter constructor.
     * @param string $parameterName
     * @param string $headerName
     */
    private function __construct(string $parameterName, string $headerName)
    {
        $this->parameterName = $parameterName;
        $this->headerName = $headerName;
    }

    /**
     * @param string $parameterName
     * @param string $headerName
     * @return self
     */
    public static function create(string $parameterName, string $headerName): self
    {
        return new self($parameterName, $headerName);
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $this->parameterName === $parameter->getName();
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(GatewayHeaderConverter::class, [
            $this->parameterName,
            $this->headerName,
        ]);
    }
}
