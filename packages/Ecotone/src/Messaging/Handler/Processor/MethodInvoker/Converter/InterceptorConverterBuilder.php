<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class AnnotationInterceptorConverterBuilder
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InterceptorConverterBuilder implements ParameterConverterBuilder
{
    /**
     * @param object[] $endpointAnnotations
     */
    private function __construct(private InterfaceParameter $parameter, private InterfaceToCall $interceptedInterface, private array $endpointAnnotations)
    {
    }

    /**
     * @param object[] $endpointAnnotations
     */
    public static function create(InterfaceParameter $parameter, InterfaceToCall $interceptedInterface, array $endpointAnnotations): self
    {
        return new self($parameter, $interceptedInterface, $endpointAnnotations);
    }

    /**
     * @inheritDoc
     */
    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $this->parameter->getName() === $parameter->getName();
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): ParameterConverter
    {
        return new InterceptorConverter($this->parameter, $this->interceptedInterface, $this->endpointAnnotations);
    }
}
