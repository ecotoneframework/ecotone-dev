<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\PollableMessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use PHPUnit\Framework\TestCase;
use Monorepo\CrossModuleTests\Fixture\FailureHandler\ExampleFailureCommandHandler;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Sqs\AbstractConnectionTest;

final class MessageChannelConfigurationTest extends TestCase
{
    const CHANNEL_NAME = "async";
    const ERROR_CHANNEL = 'customErrorChannel';

    /**
     * @dataProvider channelProvider
     */
    public function test_using_requeuing_on_failure(
        PollableMessageChannelBuilder $messageChannelBuilder,
        array $services = [],
        array $skippedModulePackageNames = [],
        \Closure $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames($skippedModulePackageNames)
                ->withExtensionObjects([$messageChannelBuilder])
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail')
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertNotNull($ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive());
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_using_default_error_channel(
        PollableMessageChannelBuilder $messageChannelBuilder,
        array $services = [],
        array $skippedModulePackageNames = [],
        \Closure $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames($skippedModulePackageNames)
                ->withExtensionObjects([
                    $messageChannelBuilder,
                    SimpleMessageChannelBuilder::createQueueChannel(self::ERROR_CHANNEL)
                ])
                ->withDefaultErrorChannel(self::ERROR_CHANNEL)
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail')
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertNotNull($ecotoneLite->getMessageChannel(self::ERROR_CHANNEL)->receive());
        $this->assertNull($ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive());
    }

    public function channelProvider()
    {
        yield "in memory" => [
            SimpleMessageChannelBuilder::createQueueChannel(self::CHANNEL_NAME),
            [],
            ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {}
        ];
        yield "dbal" => [
            DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanUpDbal();
            }
        ];
        yield "amqp" => [
            AmqpBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [AmqpConnectionFactory::class => AmqpMessagingTest::getRabbitConnectionFactory()],
            ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanRabbitMQ();
            }
        ];
        yield "redis" => [
            RedisBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [RedisConnectionFactory::class => \Test\Ecotone\Redis\AbstractConnectionTest::getConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanUpRedis();
            }
        ];
        yield "sqs" => [
            SqsBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [SqsConnectionFactory::class => AbstractConnectionTest::getConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanUpSqs();
            }
        ];
    }
}