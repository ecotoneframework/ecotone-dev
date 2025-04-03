<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use PHPUnit\Framework\TestCase;
use Monorepo\CrossModuleTests\Fixture\FailureHandler\ExampleFailureCommandHandler;
use Ramsey\Uuid\Uuid;
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
                ->withExtensionObjects([$messageChannelBuilder]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ["command" => 2])
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false, maxExecutionTimeInMilliseconds: 3000));

        $this->assertFalse($ecotoneLite->sendQueryWithRouting("handler.isSuccessful"));

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false, maxExecutionTimeInMilliseconds: 3000));

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
                ->withDefaultErrorChannel(self::ERROR_CHANNEL),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotoneLite->sendCommandWithRoutingKey('handler.fail', ['command' => 0]);

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false, maxExecutionTimeInMilliseconds: 3000));

        $this->assertNotNull($ecotoneLite->getMessageChannel(self::ERROR_CHANNEL)->receive());
        $this->assertNull($ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive());
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_custom_serialization(
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
                ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ['command' => 2]);

        $this->assertEquals(
            MediaType::createApplicationJson(),
            $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive()->getHeaders()->getContentType()
        );
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_serialization_on_the_channel(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $skippedModulePackageNames,
        \Closure                               $closure
    ): void
    {
        Assert::isTrue(method_exists($messageChannelBuilder, 'withDefaultConversionMediaType'), "MessageChannelBuilder should have method withDefaultConversionMediaType");

        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(array_diff($skippedModulePackageNames, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                        ->withDefaultConversionMediaType(MediaType::APPLICATION_JSON)
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ['command' => 2]);

        $this->assertEquals(
            MediaType::createApplicationJson(),
            $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive()->getHeaders()->getContentType()
        );
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_it_passes_all_application_headers_by_default(
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
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ['command' => 2], metadata: [
                'token' => '123',
                'userId' => '321'
            ]);

        $message = $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive();
        $this->assertEquals(            '123', $message->getHeaders()->get('token'));
        $this->assertEquals('321',$message->getHeaders()->get('userId'));
    }

    /**
     * @dataProvider channelProvider
     */
    public function test_it_passes_filtered_application_headers(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $skippedModulePackageNames,
        \Closure                               $closure
    ): void
    {
        Assert::isTrue(method_exists($messageChannelBuilder, 'withHeaderMapping'), "MessageChannelBuilder should have method withDefaultConversionMediaType");

        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(array_diff($skippedModulePackageNames, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                        ->withHeaderMapping('token')
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRoutingKey('handler.fail', ['command' => 2], metadata: [
                'token' => '123',
                'userId' => '321'
            ]);

        $message = $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive();
        $this->assertEquals(            '123', $message->getHeaders()->get('token'));
    }

    public static function channelProvider()
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
        yield "kafka" => [
            KafkaMessageChannelBuilder::create(self::CHANNEL_NAME, topicName: Uuid::uuid4()->toString())
                ->withReceiveTimeout(100),
            [KafkaBrokerConfiguration::class => \Test\Ecotone\Kafka\ConnectionTestCase::getConnection()],
            ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]),
            function() {
            }
        ];
    }
}