<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Messaging\Attribute\Parameter\Reference as ReferenceAttribute;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

/**
 * licence Enterprise
 */
final class ClosureExpressionParameterConverterBuilder implements ParameterConverterBuilder
{
    private function __construct(
        private string $parameterName,
        private object $attributeWithExpression,
        private AttributeDeclaration $attributeDeclaration,
    ) {
    }

    public static function create(string $parameterName, object $attributeWithExpression, InterfaceToCall $interfaceToCall): self
    {
        return new self(
            $parameterName,
            $attributeWithExpression,
            new AttributeDeclaration(
                get_class($attributeWithExpression),
                $interfaceToCall->getInterfaceName(),
                $interfaceToCall->getMethodName(),
                $parameterName,
            ),
        );
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    public function compile(InterfaceToCall $interfaceToCall): Definition
    {
        $valueFromHeaderName = $this->attributeWithExpression instanceof Header ? $this->attributeWithExpression->getHeaderName() : null;
        $valueFromPayload = $this->attributeWithExpression instanceof Payload;
        $staticAdditionalContext = [];
        if ($this->attributeWithExpression instanceof ReferenceAttribute) {
            $staticAdditionalContext['service'] = new Reference($this->attributeWithExpression->getReferenceName() ?: $interfaceToCall->getParameterWithName($this->parameterName)->getTypeHint());
        }

        return new Definition(ClosureExpressionParameterConverter::class, [
            Reference::to(ExpressionEvaluationService::REFERENCE),
            AttributeDefinition::fromObject($this->attributeWithExpression, $this->attributeDeclaration),
            $valueFromHeaderName,
            $valueFromPayload,
            $staticAdditionalContext,
        ]);
    }
}
