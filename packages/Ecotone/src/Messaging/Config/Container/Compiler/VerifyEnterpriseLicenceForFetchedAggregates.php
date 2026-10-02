<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\FetchAggregateConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\FetchAggregateConverterBuilder;

use function is_array;

/**
 * licence Enterprise
 */
final class VerifyEnterpriseLicenceForFetchedAggregates implements CompilerPass
{
    public function __construct(private bool $isRunningForEnterpriseLicence)
    {
    }

    public function process(ContainerBuilder $builder): void
    {
        if ($this->isRunningForEnterpriseLicence) {
            return;
        }

        foreach ($builder->getDefinitions() as $definition) {
            $this->verify($definition);
        }
    }

    private function verify(mixed $argument): void
    {
        if ($argument instanceof Definition) {
            if ($argument->getClassName() === FetchAggregateConverter::class) {
                throw FetchAggregateConverterBuilder::refusalWithoutEnterpriseLicence($argument);
            }

            $this->verify($argument->getArguments());
            foreach ($argument->getMethodCalls() as $methodCall) {
                $this->verify($methodCall->getArguments());
            }
        } elseif (is_array($argument)) {
            foreach ($argument as $value) {
                $this->verify($value);
            }
        }
    }
}
