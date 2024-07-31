<?php

namespace Test\Ecotone\Modelling\Unit;

use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\AddExecutorId\AddExecutorId;
use Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\AddNotificationTimestamp\AddNotificationTimestamp;
use Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\Logger;
use Test\Ecotone\Modelling\Fixture\InterceptedCommandAggregate\LoggerRepository;

class AggregateWithFactoryAndActionTest extends TestCase
{
    public function test_interceptors_on_aggregate_with_factory_and_action_are_called_once(): void
    {
        $addExecutorIdInterceptor = new AddExecutorId();
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [Logger::class, AddNotificationTimestamp::class, LoggerRepository::class, AddExecutorId::class],
            [new AddNotificationTimestamp(), new LoggerRepository(), $addExecutorIdInterceptor]
        );

        $ecotone->sendCommandWithRoutingKey('log', ['loggerId' => 1, 'data' => 'some data']);

        self::assertSame(1, $addExecutorIdInterceptor->getCalledCount());
    }
}