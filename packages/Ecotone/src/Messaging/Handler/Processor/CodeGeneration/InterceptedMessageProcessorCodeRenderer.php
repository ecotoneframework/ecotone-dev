<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\CodeGeneration;

/**
 * licence Apache-2.0
 */
final class InterceptedMessageProcessorCodeRenderer
{
    /**
     * @param AroundInterceptorMetadata[] $arounds
     */
    public function render(string $className, string $leafMethodName, array $arounds): string
    {
        if ($arounds === []) {
            return <<<PHP
                <?php

                if (class_exists('{$className}', false)) {
                    return;
                }

                final class {$className} implements \Ecotone\Messaging\Handler\MessageProcessor
                {
                    public function __construct(
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker \$invoker,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter \$resultConverter,
                    ) {
                    }

                    public function process(\Ecotone\Messaging\Message \$message): ?\Ecotone\Messaging\Message
                    {
                        return \$this->resultConverter->convertToMessage(\$message, \$this->invoker->execute(\$message));
                    }
                }

                PHP;
        }

        $invocationClassName = $className . '_Invocation';
        $aroundConstructorParameters = $this->renderAroundConstructorParameters($arounds);
        $aroundForwardedArguments = $this->renderAroundForwardedArguments($arounds);
        $proceedCases = $this->renderProceedCases($arounds, $leafMethodName);

        return <<<PHP
            <?php

            if (class_exists('{$className}', false)) {
                return;
            }

            final class {$invocationClassName} implements \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation
            {
                private array \$arguments;
                private int \$step = 0;

                public function __construct(
                    private \Ecotone\Messaging\Message \$message,
                    private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker \$invoker,
            {$aroundConstructorParameters}
                ) {
                    \$this->arguments = \$invoker->getArguments(\$message);
                }

                public function proceed(): mixed
                {
                    switch (\$this->step) {
            {$proceedCases}
                        default:
                            \$objectToInvokeOn = \$this->invoker->getObjectToInvokeOn(\$this->message);
                            return is_string(\$objectToInvokeOn)
                                ? \$objectToInvokeOn::{$leafMethodName}(...\$this->arguments)
                                : \$objectToInvokeOn->{$leafMethodName}(...\$this->arguments);
                    }
                }

                public function cloneCurrentState(): self
                {
                    \$clone = new self(\$this->message, \$this->invoker, {$aroundForwardedArguments});
                    \$clone->step = \$this->step;

                    return \$clone;
                }

                public function getObjectToInvokeOn(): string|object
                {
                    return \$this->invoker->getObjectToInvokeOn(\$this->message);
                }

                public function getMethodName(): string
                {
                    return '{$leafMethodName}';
                }

                public function getInterfaceToCall(): \Ecotone\Messaging\Handler\InterfaceToCall
                {
                    return \Ecotone\Messaging\Handler\InterfaceToCall::create(\$this->getObjectToInvokeOn(), '{$leafMethodName}');
                }

                public function getName(): string
                {
                    \$object = \$this->getObjectToInvokeOn();

                    return (is_string(\$object) ? \$object : get_class(\$object)) . '::{$leafMethodName}';
                }

                public function getArguments(): array
                {
                    return array_values(\$this->arguments);
                }

                public function replaceArgument(string \$parameterName, \$value): void
                {
                    if (! isset(\$this->arguments[\$parameterName])) {
                        throw \Ecotone\Messaging\Support\InvalidArgumentException::create("Parameter with name `{\$parameterName}` does not exist");
                    }
                    \$this->arguments[\$parameterName] = \$value;
                }
            }

            final class {$className} implements \Ecotone\Messaging\Handler\MessageProcessor
            {
                public function __construct(
                    private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker \$invoker,
                    private \Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter \$resultConverter,
            {$aroundConstructorParameters}
                ) {
                }

                public function process(\Ecotone\Messaging\Message \$message): ?\Ecotone\Messaging\Message
                {
                    return \$this->resultConverter->convertToMessage(
                        \$message,
                        (new {$invocationClassName}(\$message, \$this->invoker, {$aroundForwardedArguments}))->proceed(),
                    );
                }
            }

            PHP;
    }

    /**
     * @param AroundInterceptorMetadata[] $arounds
     */
    private function renderAroundConstructorParameters(array $arounds): string
    {
        $parameters = [];
        foreach (array_keys($arounds) as $index) {
            $parameters[] = "        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor \$around{$index},";
        }

        return implode("\n", $parameters);
    }

    /**
     * @param AroundInterceptorMetadata[] $arounds
     */
    private function renderAroundForwardedArguments(array $arounds): string
    {
        $arguments = [];
        foreach (array_keys($arounds) as $index) {
            $arguments[] = "\$this->around{$index}";
        }

        return implode(', ', $arguments);
    }

    /**
     * @param AroundInterceptorMetadata[] $arounds
     */
    private function renderProceedCases(array $arounds, string $leafMethodName): string
    {
        $cases = [];
        foreach ($arounds as $index => $around) {
            $nextStep = $index + 1;
            $interceptorCall = "\$this->around{$index}->getReferenceToCall()->{$around->methodName}(...\$this->around{$index}->getArguments(\$this, \$this->message))";
            $caseLines = [
                "            case {$index}:",
                "                \$this->step = {$nextStep};",
            ];
            if ($around->hasMethodInvocation) {
                $caseLines[] = "                return {$interceptorCall};";
            } else {
                $caseLines[] = "                {$interceptorCall};";
                $caseLines[] = '                return $this->proceed();';
            }
            $cases[] = implode("\n", $caseLines);
        }

        return implode("\n", $cases);
    }
}
