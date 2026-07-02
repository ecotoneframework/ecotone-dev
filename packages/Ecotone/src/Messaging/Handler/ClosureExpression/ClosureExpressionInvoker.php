<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\ClosureExpression;

use Closure;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Enterprise
 */
final class ClosureExpressionInvoker
{
    /**
     * @param ClosureParameterResolver[] $parameterResolvers
     */
    public function __construct(
        private Closure $expression,
        private array $parameterResolvers,
        private LicenceDecider $licenceDecider,
    ) {
    }

    public function invoke(Message $message, array $additionalContext = []): mixed
    {
        if (! $this->licenceDecider->hasEnterpriseLicence()) {
            throw LicensingException::create('Closure given as attribute expression is available as part of Ecotone Enterprise.');
        }

        $arguments = [];
        foreach ($this->parameterResolvers as $parameterResolver) {
            $arguments[] = $parameterResolver->resolve($message, $additionalContext);
        }

        return ($this->expression)(...$arguments);
    }
}
