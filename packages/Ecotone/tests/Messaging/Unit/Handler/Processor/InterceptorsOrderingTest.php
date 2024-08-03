<?php

namespace Test\Ecotone\Messaging\Unit\Handler\Processor;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Support\GenericMessage;
use Ecotone\Modelling\CommandBus;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\Gateway;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\GatewayInterceptors;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingAggregate;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingCase;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingServiceActivatorCase;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingStack;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingWithoutAfterCase;
use Test\Ecotone\Messaging\Fixture\InterceptorsOrdering\InterceptorOrderingInterceptors;

class InterceptorsOrderingTest extends TestCase
{
    public function testInterceptorsAreCalledInOrder(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [InterceptorOrderingCase::class, InterceptorOrderingInterceptors::class],
            [new InterceptorOrderingCase(), new InterceptorOrderingInterceptors()],
        );
        $callStack = new InterceptorOrderingStack();
        /** @var CommandBus $commandBus */
        $commandBus = $ecotone->getGateway(CommandBus::class);
        $return = $commandBus->sendWithRouting("endpoint", metadata: ['stack' => $callStack]);

        self::assertSame($callStack, $return);

        self::assertEquals(
            [
                ["beforeChangeHeaders", []],
                ["before", ["beforeChangeHeaders" => "header"]],
                ["around begin", ["beforeChangeHeaders" => "header"]],
                ["endpoint", ["beforeChangeHeaders" => "header"]],
                ["afterChangeHeaders", ["beforeChangeHeaders" => "header"]],
                ["after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["around end", ["beforeChangeHeaders" => "header"], GenericMessage::class],
            ],
            $callStack->getCalls()
        );
    }

    public function testInterceptorsAreCalledInOrderOnServiceActivator(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [InterceptorOrderingCase::class, InterceptorOrderingInterceptors::class],
            [new InterceptorOrderingCase(), new InterceptorOrderingInterceptors()],
        );
        $callStack = new InterceptorOrderingStack();
        $ecotone->sendDirectToChannel("runEndpoint", metadata: ['stack' => $callStack]);

        self::assertEquals(
            [
                ["beforeChangeHeaders", []],
                ["before", ["beforeChangeHeaders" => "header"]],
                ["around begin", ["beforeChangeHeaders" => "header"]],
                ["endpoint", ["beforeChangeHeaders" => "header"]],
                ["afterChangeHeaders", ["beforeChangeHeaders" => "header"]],
                ["after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["around end", ["beforeChangeHeaders" => "header"], GenericMessage::class],
            ],
            $callStack->getCalls()
        );
    }

    public function testInterceptorsAreCalledInOrderWithGateway(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [InterceptorOrderingCase::class, Gateway::class, InterceptorOrderingInterceptors::class, GatewayInterceptors::class],
            [new InterceptorOrderingCase(), new InterceptorOrderingInterceptors(), new GatewayInterceptors()],
        );
        $callStack = new InterceptorOrderingStack();
        $return = $ecotone->getGateway(Gateway::class)->runWithReturn(['stack' => $callStack]);

        self::assertSame($callStack, $return);

        self::assertEquals(
            [
                ["gateway::before", []],
                ["gateway::around begin", []],
                ["beforeChangeHeaders", []],
                ["before", ["beforeChangeHeaders" => "header"]],
                ["around begin", ["beforeChangeHeaders" => "header"]],
                ["endpoint", ["beforeChangeHeaders" => "header"]],
                ["afterChangeHeaders", ["beforeChangeHeaders" => "header"]],
                ["after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["around end", ["beforeChangeHeaders" => "header"], GenericMessage::class],
                ["gateway::after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["gateway::around end", [], GenericMessage::class],
            ],
            $callStack->getCalls()
        );
    }

    public function testInterceptorsAreCalledInOrderWithAggregateFactoryMethod(): void
    {
        $callStack = new InterceptorOrderingStack();
        EcotoneLite::bootstrapFlowTesting(
            [InterceptorOrderingAggregate::class, InterceptorOrderingInterceptors::class],
            [new InterceptorOrderingInterceptors()],
        )->sendCommandWithRoutingKey("endpoint", metadata: ['aggregate.id' => 'id', 'stack' => $callStack]);

        self::assertEquals(
            [
                ["beforeChangeHeaders", []],
                ["before", ["beforeChangeHeaders" => "header"]],
                ["afterChangeHeaders", ["beforeChangeHeaders" => "header"]],
                ["after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["beforeChangeHeaders", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["before", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
                ["around begin", ["beforeChangeHeaders" => "header", 'afterChangeHeaders' => 'header']],
                ["factory", ["beforeChangeHeaders" => "header", 'afterChangeHeaders' => 'header']],
                ["around end", ["beforeChangeHeaders" => "header", 'afterChangeHeaders' => 'header'], InterceptorOrderingAggregate::class],
                ["afterChangeHeaders", ["beforeChangeHeaders" => "header", 'afterChangeHeaders' => 'header']],
                ["after", ["beforeChangeHeaders" => "header", "afterChangeHeaders" => "header"]],
            ],
            $callStack->getCalls()
        );
    }
}