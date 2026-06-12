<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ResiliencyThroughGeneratedProcessorTest extends TestCase
{
    public function test_around_retry_interceptor_succeeds_on_second_attempt_using_cloned_invocation(): void
    {
        $flakyService = new class () {
            private int $attempts = 0;
            private array $orders = [];

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new RuntimeException('connection lost');
                }
                $this->orders[] = $order;
            }

            #[QueryHandler('order.getAll')]
            public function getAll(): array
            {
                return $this->orders;
            }

            #[QueryHandler('order.getAttempts')]
            public function getAttempts(): int
            {
                return $this->attempts;
            }
        };
        $retryInterceptor = new class () {
            #[Around(pointcut: CommandHandler::class)]
            public function retry(MethodInvocation $methodInvocation): mixed
            {
                try {
                    return $methodInvocation->cloneCurrentState()->proceed();
                } catch (Throwable) {
                    return $methodInvocation->cloneCurrentState()->proceed();
                }
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$flakyService::class, $retryInterceptor::class],
            [$flakyService, $retryInterceptor],
        );
        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'book');

        self::assertSame(['book'], $ecotoneLite->sendQueryWithRouting('order.getAll'));
        self::assertSame(2, $ecotoneLite->sendQueryWithRouting('order.getAttempts'));
    }

    public function test_handler_exception_propagates_unwrapped_out_of_generated_processor(): void
    {
        $failingService = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                throw new RuntimeException('database is down');
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$failingService::class],
            [$failingService],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database is down');
        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'book');
    }

    public function test_before_interceptor_returning_null_stops_the_chain(): void
    {
        $orderService = new class () {
            private array $orders = [];

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
                $this->orders[] = $order;
            }

            #[QueryHandler('order.getAll')]
            public function getAll(): array
            {
                return $this->orders;
            }
        };
        $dropAllMessages = new class () {
            #[Before(pointcut: CommandHandler::class)]
            public function drop(string $payload): ?string
            {
                return null;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class, $dropAllMessages::class],
            [$orderService, $dropAllMessages],
        );
        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'book');

        self::assertSame([], $ecotoneLite->sendQueryWithRouting('order.getAll'));
    }
}
