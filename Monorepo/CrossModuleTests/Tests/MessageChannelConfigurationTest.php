<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Tests;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Test\LicenceTesting;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Enqueue\Redis\RedisConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use Interop\Amqp\AmqpConnectionFactory;
use Monorepo\ExampleApp\ExampleAppCaseTrait;
use Monorepo\ExampleApp\Symfony\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('channelProvider')]
    public function test_using_requeuing_on_failure(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages($modulePackagesToLoad)
                ->withExtensionObjects([
                    $messageChannelBuilder,
                    InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRouting('handler.fail', ["command" => 2])
            ->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false, executionTimeLimitInMilliseconds: 3000));

        $this->assertFalse($ecotoneLite->sendQueryWithRouting("handler.isSuccessful"));

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false, executionTimeLimitInMilliseconds: 3000));

        $this->assertTrue($ecotoneLite->sendQueryWithRouting('handler.isSuccessful'));
    }

    #[DataProvider('channelProvider')]
    public function test_using_default_error_channel(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages($modulePackagesToLoad)
                ->withExtensionObjects([
                    $messageChannelBuilder,
                    SimpleMessageChannelBuilder::createQueueChannel(self::ERROR_CHANNEL)
                ])
                ->withDefaultErrorChannel(self::ERROR_CHANNEL),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotoneLite->sendCommandWithRouting('handler.fail', ['command' => 0]);

        $ecotoneLite->run(self::CHANNEL_NAME, ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false, executionTimeLimitInMilliseconds: 3000));

        $this->assertNotNull($ecotoneLite->getMessageChannel(self::ERROR_CHANNEL)->receive());
        $this->assertNull($ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive());
    }

    #[DataProvider('channelProvider')]
    public function test_custom_serialization(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages(array_merge($modulePackagesToLoad, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                ])
                ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRouting('handler.fail', ['command' => 2]);

        $this->assertEquals(
            MediaType::createApplicationJson(),
            $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive()->getHeaders()->getContentType()
        );
    }

    #[DataProvider('channelProvider')]
    public function test_serialization_on_the_channel(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        Assert::isTrue(method_exists($messageChannelBuilder, 'withDefaultConversionMediaType'), "MessageChannelBuilder should have method withDefaultConversionMediaType");

        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages(array_merge($modulePackagesToLoad, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                        ->withDefaultConversionMediaType(MediaType::APPLICATION_JSON)
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRouting('handler.fail', ['command' => 2]);

        $this->assertEquals(
            MediaType::createApplicationJson(),
            $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive()->getHeaders()->getContentType()
        );
    }

    #[DataProvider('channelProvider')]
    public function test_it_passes_all_application_headers_by_default(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages(array_merge($modulePackagesToLoad, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRouting('handler.fail', ['command' => 2], metadata: [
                'token' => '123',
                'userId' => '321'
            ]);

        $message = $ecotoneLite->getMessageChannel(self::CHANNEL_NAME)->receive();
        $this->assertEquals(            '123', $message->getHeaders()->get('token'));
        $this->assertEquals('321',$message->getHeaders()->get('userId'));
    }

    #[DataProvider('channelProvider')]
    public function test_it_passes_filtered_application_headers(
        MessageChannelWithSerializationBuilder $messageChannelBuilder,
        array                                  $services,
        array                                  $modulePackagesToLoad,
        \Closure                               $closure
    ): void
    {
        Assert::isTrue(method_exists($messageChannelBuilder, 'withHeaderMapping'), "MessageChannelBuilder should have method withDefaultConversionMediaType");

        $closure();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleFailureCommandHandler::class],
            array_merge($services, [new ExampleFailureCommandHandler()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages(array_merge($modulePackagesToLoad, [ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withExtensionObjects([
                    $messageChannelBuilder
                        ->withHeaderMapping('token')
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite
            ->sendCommandWithRouting('handler.fail', ['command' => 2], metadata: [
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
            [],
            function() {}
        ];
        yield "dbal" => [
            DbalBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [DbalConnectionFactory::class => DbalMessagingTestCase::prepareConnection()],
            [ModulePackageList::DBAL_PACKAGE],
            function() {
                MessagingTestCase::cleanUpDbal();
            }
        ];
        $amqpConnectionFactory = AmqpMessagingTestCase::getRabbitConnectionFactory();
        yield "amqp" => [
            AmqpBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [
                AmqpConnectionFactory::class => $amqpConnectionFactory,
                AmqpExtConnectionFactory::class => $amqpConnectionFactory,
                AmqpLibConnectionFactory::class => $amqpConnectionFactory,
            ],
            [ModulePackageList::AMQP_PACKAGE],
            function() {
                MessagingTestCase::cleanRabbitMQ();
            }
        ];
        yield "redis" => [
            RedisBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [RedisConnectionFactory::class => \Test\Ecotone\Redis\ConnectionTestCase::getConnection()],
            [ModulePackageList::REDIS_PACKAGE],
            function() {
                MessagingTestCase::cleanUpRedis();
            }
        ];
        yield "sqs" => [
            SqsBackedMessageChannelBuilder::create(self::CHANNEL_NAME)
                ->withReceiveTimeout(100),
            [SqsConnectionFactory::class => ConnectionTestCase::getConnection()],
            [ModulePackageList::SQS_PACKAGE],
            function() {
                MessagingTestCase::cleanUpSqs();
            }
        ];
        yield "kafka" => [
            KafkaMessageChannelBuilder::create(self::CHANNEL_NAME, topicName: Uuid::uuid4()->toString())
                ->withReceiveTimeout(100),
            [KafkaBrokerConfiguration::class => \Test\Ecotone\Kafka\ConnectionTestCase::getConnection()],
            [ModulePackageList::KAFKA_PACKAGE],
            function() {
            }
        ];
    }
}