<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Handler\Processor\CodeGeneration;

use Ecotone\Messaging\Config\Container\CodeGeneration\GeneratedClassWriter;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\Processor\CodeGeneration\AroundInterceptorMetadata;
use Ecotone\Messaging\Handler\Processor\CodeGeneration\InterceptedMessageProcessorCodeRenderer;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInterceptor;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\MethodInvocationConverter;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvoker;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerStaticObjectResolver;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class GeneratedInvocationSemanticsTest extends TestCase
{
    public function test_around_without_method_invocation_runs_as_pass_through_hook_and_chain_proceeds(): void
    {
        $auditor = new class () {
            public array $audited = [];

            public function audit(string $payload): void
            {
                $this->audited[] = $payload;
            }
        };
        $orderService = $this->orderService();

        $invocation = $this->buildInvocation(
            'PassThroughAround',
            $orderService,
            [[$auditor, 'audit', [$this->payloadConverter()], false]],
        );

        self::assertSame('placed: book', $invocation->proceed());
        self::assertSame(['book'], $auditor->audited);
    }

    public function test_around_with_method_invocation_calling_proceed_continues_the_chain(): void
    {
        $transaction = new class () {
            public function transactional(MethodInvocation $invocation): mixed
            {
                return 'tx(' . $invocation->proceed() . ')';
            }
        };

        $invocation = $this->buildInvocation(
            'ProceedingAround',
            $this->orderService(),
            [[$transaction, 'transactional', [new MethodInvocationConverter()], true]],
        );

        self::assertSame('tx(placed: book)', $invocation->proceed());
    }

    public function test_around_with_method_invocation_not_calling_proceed_stops_the_chain(): void
    {
        $blocker = new class () {
            public function block(MethodInvocation $invocation): string
            {
                return 'blocked';
            }
        };
        $orderService = $this->orderService();

        $invocation = $this->buildInvocation(
            'BlockingAround',
            $orderService,
            [[$blocker, 'block', [new MethodInvocationConverter()], true]],
        );

        self::assertSame('blocked', $invocation->proceed());
        self::assertSame(0, $orderService->placedCount);
    }

    public function test_replaced_argument_is_visible_to_the_final_method_invocation(): void
    {
        $replacer = new class () {
            public function replace(MethodInvocation $invocation): mixed
            {
                $invocation->replaceArgument('order', 'replaced');

                return $invocation->proceed();
            }
        };

        $invocation = $this->buildInvocation(
            'ReplacingAround',
            $this->orderService(),
            [[$replacer, 'replace', [new MethodInvocationConverter()], true]],
        );

        self::assertSame('placed: replaced', $invocation->proceed());
    }

    public function test_cloned_invocation_re_executes_downstream_steps_supporting_retries(): void
    {
        $flakyService = new class () {
            public int $placedCount = 0;

            public function place(string $order): string
            {
                $this->placedCount++;
                if ($this->placedCount === 1) {
                    throw new RuntimeException('connection lost');
                }

                return 'placed: ' . $order;
            }
        };
        $retry = new class () {
            public function retry(MethodInvocation $invocation): mixed
            {
                try {
                    return $invocation->cloneCurrentState()->proceed();
                } catch (Throwable) {
                    return $invocation->cloneCurrentState()->proceed();
                }
            }
        };

        $invocation = $this->buildInvocation(
            'RetryingAround',
            $flakyService,
            [[$retry, 'retry', [new MethodInvocationConverter()], true]],
        );

        self::assertSame('placed: book', $invocation->proceed());
        self::assertSame(2, $flakyService->placedCount);
    }

    public function test_mixed_arounds_keep_registration_order_with_pass_through_running_inside_controlling_one(): void
    {
        $trace = new class () {
            public array $entries = [];
        };
        $transaction = new class () {
            public mixed $trace = null;

            public function transactional(MethodInvocation $invocation): mixed
            {
                $this->trace->entries[] = 'tx start';
                $result = $invocation->proceed();
                $this->trace->entries[] = 'tx end';

                return $result;
            }
        };
        $transaction->trace = $trace;
        $auditor = new class () {
            public mixed $trace = null;

            public function audit(): void
            {
                $this->trace->entries[] = 'audit';
            }
        };
        $auditor->trace = $trace;

        $invocation = $this->buildInvocation(
            'MixedArounds',
            $this->orderService(),
            [
                [$transaction, 'transactional', [new MethodInvocationConverter()], true],
                [$auditor, 'audit', [], false],
            ],
        );

        self::assertSame('placed: book', $invocation->proceed());
        self::assertSame(['tx start', 'audit', 'tx end'], $trace->entries);
    }

    private function orderService(): object
    {
        return new class () {
            public int $placedCount = 0;

            public function place(string $order): string
            {
                $this->placedCount++;

                return 'placed: ' . $order;
            }
        };
    }

    private function payloadConverter(): ParameterConverter
    {
        return new class () implements ParameterConverter {
            public function getArgumentFrom(Message $message): mixed
            {
                return $message->getPayload();
            }
        };
    }

    /**
     * @param array<array{object, string, ParameterConverter[], bool}> $arounds
     */
    private function buildInvocation(string $baseName, object $service, array $arounds): MethodInvocation
    {
        $generatedClass = (new GeneratedClassWriter())->write(
            $baseName,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_generated_invocation_semantics',
            fn (string $className) => (new InterceptedMessageProcessorCodeRenderer())->render(
                $className,
                'place',
                array_map(fn (array $around) => new AroundInterceptorMetadata($around[1], $around[3]), $arounds),
            ),
        );
        require_once $generatedClass->filePath;
        $invocationClassName = $generatedClass->className . '_Invocation';

        $invoker = new MethodInvoker(
            new MethodInvokerStaticObjectResolver($service),
            'place',
            [$this->payloadConverter()],
            ['order'],
        );
        $aroundInterceptors = array_map(
            fn (array $around) => new AroundMethodInterceptor($around[0], $around[1], $around[2], $around[3]),
            $arounds,
        );

        return new $invocationClassName(
            MessageBuilder::withPayload('book')->build(),
            $invoker,
            ...$aroundInterceptors,
        );
    }
}
