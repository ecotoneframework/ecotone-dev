<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Message;

/**
 * licence Enterprise
 */
final class ClosureExpressionParameterConverter implements ParameterConverter
{
    public function __construct(
        private ClosureExpressionInvoker $closureExpressionInvoker,
        private ?string $valueFromHeaderName = null,
        private bool $valueFromPayload = false,
        private array $staticAdditionalContext = [],
    ) {
    }

    public function getArgumentFrom(Message $message): mixed
    {
        return $this->closureExpressionInvoker->invoke($message, AdditionalContextResolver::resolve($message, $this->staticAdditionalContext, $this->valueFromHeaderName, $this->valueFromPayload));
    }
}
