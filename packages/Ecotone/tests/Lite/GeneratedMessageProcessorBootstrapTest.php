<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class GeneratedMessageProcessorBootstrapTest extends TestCase
{
    public function test_endpoints_execute_through_generated_processor_classes_written_to_cache_directory(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/ecotone_generated_processor_bootstrap/' . uniqid('', true);
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

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class],
            [$orderService],
            ServiceConfiguration::createWithDefaults()->withCacheDirectoryPath($cacheDirectory),
        );
        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'book');

        self::assertSame(['book'], $ecotoneLite->sendQueryWithRouting('order.getAll'));
        self::assertNotEmpty(glob($cacheDirectory . '/{,*/,*/*/}handlers/MessageProcessor__*.php', GLOB_BRACE));
    }

    public function test_around_interceptor_executes_through_generated_invocation(): void
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
        $interceptor = new class () {
            #[Around(pointcut: CommandHandler::class)]
            public function intercept(MethodInvocation $methodInvocation): mixed
            {
                $methodInvocation->replaceArgument('order', 'intercepted book');

                return $methodInvocation->proceed();
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$orderService::class, $interceptor::class],
            [$orderService, $interceptor],
        );
        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'book');

        self::assertSame(['intercepted book'], $ecotoneLite->sendQueryWithRouting('order.getAll'));
    }
}
