<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Attribute\WithExpression;
use Ecotone\Messaging\Handler\ExpressionEvaluationException;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\ExpressionLocation;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Throwable;

/**
 * Carries intercepted attribute together with compiled expression program.
 * Closure expressions execute with parameter resolvers compiled at container build time,
 * string expressions evaluate with Expression Language.
 */
/**
 * licence Enterprise
 */
final class AttributeExpressionExecutor
{
    private Closure|string|null $expression;

    /**
     * @param ClosureParameterResolver[] $closureParameterResolvers
     */
    public function __construct(
        private object $attribute,
        private ExpressionEvaluationService $expressionEvaluationService,
        private array $closureParameterResolvers,
        private ExpressionLocation $location,
    ) {
        $this->expression = $attribute instanceof WithExpression ? $attribute->getExpression() : null;
    }

    public static function withoutExpression(ExpressionEvaluationService $expressionEvaluationService, ExpressionLocation $location): self
    {
        return new self(new class {
        }, $expressionEvaluationService, [], $location);
    }

    public function getAttribute(): object
    {
        return $this->attribute;
    }

    public function hasExpression(): bool
    {
        return $this->expression !== null && $this->expression !== '';
    }

    public function location(): ExpressionLocation
    {
        return $this->location;
    }

    public function execute(Message $message, array $additionalContext = []): mixed
    {
        $expression = $this->expression;
        if (! $this->hasExpression()) {
            throw InvalidArgumentException::create(sprintf('Attribute %s has no expression to execute', get_class($this->attribute)));
        }

        try {
            if ($expression instanceof Closure) {
                $arguments = [];
                foreach ($this->closureParameterResolvers as $parameterResolver) {
                    $arguments[] = $parameterResolver->resolve($message, $additionalContext);
                }

                return $expression(...$arguments);
            }

            return $this->expressionEvaluationService->evaluateWithMessage($expression, $message, $additionalContext);
        } catch (Throwable $exception) {
            throw ExpressionEvaluationException::wrapping($this->location, $exception);
        }
    }
}
