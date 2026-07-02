<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use function array_key_exists;

use Closure;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Enterprise
 */
final class ContextClosureExpressionInvoker
{
    /**
     * @param array<array{name: string, hasDefaultValue: bool, defaultValue: mixed}> $parameterSpecifications
     */
    public function __construct(
        private Closure $expression,
        private array $parameterSpecifications,
    ) {
    }

    public function invoke(array $context): mixed
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
