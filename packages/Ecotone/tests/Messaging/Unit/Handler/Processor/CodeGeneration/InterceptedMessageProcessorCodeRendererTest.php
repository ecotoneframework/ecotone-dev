<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor\CodeGeneration;

use Ecotone\Messaging\Handler\Processor\CodeGeneration\AroundInterceptorMetadata;
use Ecotone\Messaging\Handler\Processor\CodeGeneration\InterceptedMessageProcessorCodeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterceptedMessageProcessorCodeRendererTest extends TestCase
{
    public function test_it_renders_processor_without_around_interceptors(): void
    {
        $code = (new InterceptedMessageProcessorCodeRenderer())->render('GeneratedProcessor', 'place', []);

        self::assertSame(
            <<<'PHP'
                <?php

                if (class_exists('GeneratedProcessor', false)) {
                    return;
                }

                final class GeneratedProcessor implements \Ecotone\Messaging\Handler\MessageProcessor
                {
                    public function __construct(
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker $invoker,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter $resultConverter,
                    ) {
                    }

                    public function process(\Ecotone\Messaging\Message $message): ?\Ecotone\Messaging\Message
                    {
                        return $this->resultConverter->convertToMessage($message, $this->invoker->execute($message));
                    }
                }

                PHP,
            $code,
        );
    }

    public function test_it_renders_processor_with_around_interceptors_and_invocation_class(): void
    {
        $code = (new InterceptedMessageProcessorCodeRenderer())->render('GeneratedProcessor', 'place', [
            new AroundInterceptorMetadata('transactional', hasMethodInvocation: true),
            new AroundInterceptorMetadata('audit', hasMethodInvocation: false),
        ]);

        self::assertSame(
            <<<'PHP'
                <?php

                if (class_exists('GeneratedProcessor', false)) {
                    return;
                }

                final class GeneratedProcessor_Invocation implements \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation
                {
                    private array $arguments;
                    private int $step = 0;

                    public function __construct(
                        private \Ecotone\Messaging\Message $message,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker $invoker,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor $around0,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor $around1,
                    ) {
                        $this->arguments = $invoker->getArguments($message);
                    }

                    public function proceed(): mixed
                    {
                        switch ($this->step) {
                            case 0:
                                $this->step = 1;
                                return $this->around0->getReferenceToCall()->transactional(...$this->around0->getArguments($this, $this->message));
                            case 1:
                                $this->step = 2;
                                $this->around1->getReferenceToCall()->audit(...$this->around1->getArguments($this, $this->message));
                                return $this->proceed();
                            default:
                                $objectToInvokeOn = $this->invoker->getObjectToInvokeOn($this->message);
                                return is_string($objectToInvokeOn)
                                    ? $objectToInvokeOn::place(...$this->arguments)
                                    : $objectToInvokeOn->place(...$this->arguments);
                        }
                    }

                    public function cloneCurrentState(): self
                    {
                        $clone = new self($this->message, $this->invoker, $this->around0, $this->around1);
                        $clone->step = $this->step;

                        return $clone;
                    }

                    public function getObjectToInvokeOn(): string|object
                    {
                        return $this->invoker->getObjectToInvokeOn($this->message);
                    }

                    public function getMethodName(): string
                    {
                        return 'place';
                    }

                    public function getInterfaceToCall(): \Ecotone\Messaging\Handler\InterfaceToCall
                    {
                        return \Ecotone\Messaging\Handler\InterfaceToCall::create($this->getObjectToInvokeOn(), 'place');
                    }

                    public function getName(): string
                    {
                        $object = $this->getObjectToInvokeOn();

                        return (is_string($object) ? $object : get_class($object)) . '::place';
                    }

                    public function getArguments(): array
                    {
                        return array_values($this->arguments);
                    }

                    public function replaceArgument(string $parameterName, $value): void
                    {
                        if (! isset($this->arguments[$parameterName])) {
                            throw \Ecotone\Messaging\Support\InvalidArgumentException::create("Parameter with name `{$parameterName}` does not exist");
                        }
                        $this->arguments[$parameterName] = $value;
                    }
                }

                final class GeneratedProcessor implements \Ecotone\Messaging\Handler\MessageProcessor
                {
                    public function __construct(
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker $invoker,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\ResultToMessageConverter $resultConverter,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor $around0,
                        private \Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor $around1,
                    ) {
                    }

                    public function process(\Ecotone\Messaging\Message $message): ?\Ecotone\Messaging\Message
                    {
                        return $this->resultConverter->convertToMessage(
                            $message,
                            (new GeneratedProcessor_Invocation($message, $this->invoker, $this->around0, $this->around1))->proceed(),
                        );
                    }
                }

                PHP,
            $code,
        );
    }
}
