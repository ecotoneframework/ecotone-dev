<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use PHPUnit\Framework\TestCase;
use Monorepo\CrossModuleTests\Fixture\FailureHandler\ExampleFailureCommandHandler;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Sqs\ConnectionTestCase;

final class MessageChannelConfigurationTest extends TestCase
{
    use ExampleAppCaseTrait;

    const CHANNEL_NAME = "async";
    const ERROR_CHANNEL = 'customErrorChannel';

    /**
     * @dataProvider channelProvider
     */
    public function test_using_requeuing_on_failure(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $skippedModulePackageNames,
        \Closure                               $closure
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
            ->sendCommandWithRoutingKey('handler.fail', ["command" => 2])
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertFalse($ecotoneLite->sendQueryWithRouting("handler.isSuccessful"));

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertTrue($ecotoneLite->sendQueryWithRouting('handler.isSuccessful'));
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_using_default_error_channel(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $skippedModulePackageNames,
        \Closure                               $closure
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
        $ecotoneLite->sendCommandWithRoutingKey('handler.fail', ['command' => 0]);

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertNotNull($ecotoneLite->getMessageChannel(self::ERROR_CHANNEL)->receive());
        $this->assertNull($ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive());
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_default_serialization(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $skippedModulePackageNames,
        \Closure                               $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(array_diff($skippedModulePackageNames, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                ])
                ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON)
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ['command' => 0])
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

        $this->assertEquals(
            MediaType::createApplicationJson(),
            $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive()->getHeaders()->getContentType()
        );
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
            [AmqpConnectionFactory::class => AmqpMessagingTestCase::getRabbitConnectionFactory()],
            ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanRabbitMQ();
            }
        ];
        yield "redis" => [
            RedisBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [RedisConnectionFactory::class => \Test\Ecotone\Redis\ConnectionTestCase::getConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::REDIS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanUpRedis();
            }
        ];
        yield "sqs" => [
            SqsBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [SqsConnectionFactory::class => ConnectionTestCase::getConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
                MessagingTestCase::cleanUpSqs();
            }
        ];
    }
}