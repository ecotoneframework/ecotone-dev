<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use function array_key_exists;

use Closure;
use Ecotone\Messaging\Attribute\WithExpression;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * Carries attribute together with compiled closure expression bound to plain context variables by parameter name,
 * for evaluation without Message.
 */
/**
 * licence Enterprise
 */
final class AttributeExpressionContextExecutor
{
    private Closure $expression;

    /**
     * @param array<array{name: string, hasDefaultValue: bool, defaultValue: mixed}> $parameterSpecifications
     */
    public function __construct(
        WithExpression $attribute,
        private array $parameterSpecifications,
    ) {
        $expression = $attribute->getExpression();
        if (! $expression instanceof Closure) {
            throw InvalidArgumentException::create(sprintf('Expected closure expression inside %s attribute, got %s', get_class($attribute), get_debug_type($expression)));
        }

        $this->expression = $expression;
    }

    public function execute(array $context): mixed
    {
        $arguments = [];
        foreach ($this->parameterSpecifications as $index => $parameterSpecification) {
            if (array_key_exists($parameterSpecification['name'], $context)) {
                $arguments[] = $context[$parameterSpecification['name']];
            } elseif ($index === 0 && array_key_exists('payload', $context)) {
                $arguments[] = $context['payload'];
            } elseif ($parameterSpecification['hasDefaultValue']) {
                $arguments[] = $parameterSpecification['defaultValue'];
            } else {
                throw InvalidArgumentException::create(sprintf('Cannot resolve parameter `%s` of closure expression. Available context variables: %s', $parameterSpecification['name'], implode(', ', array_keys($context))));
            }
        }

        return ($this->expression)(...$arguments);
    }
}
