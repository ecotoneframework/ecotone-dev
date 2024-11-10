<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;

use function getenv;

use PHPUnit\Framework\TestCase;
use Test\Ecotone\Kafka\Fixture\ChannelAdapter\ExampleKafkaConfiguration;
use Test\Ecotone\Kafka\Fixture\ChannelAdapter\ExampleKafkaConsumer;

/**
 * licence Enterprise
 * @internal
 */
final class KafkaChannelAdapterTest extends TestCase
{
    public function test_sending_and_receiving_from_kafka_topic(): void
    {
        $this->markTestSkipped('Kafka not yet supported');

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [ExampleKafkaConsumer::class, ExampleKafkaConfiguration::class],
            [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                getenv('KAFKA_DSN') ?? 'localhost:9092',
            ]), new ExampleKafkaConsumer()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE])),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** @var MessagePublisher $kafkaPublisher */
        $kafkaPublisher = $ecotoneLite->getGateway(MessagePublisher::class);

        $kafkaPublisher->sendWithMetadata('exampleData', 'application/text', ['key' => 'value']);

        $ecotoneLite->run('exampleConsumer', ExecutionPollingMetadata::createWithTestingSetup(
            // waiting for initial repartitioning
            maxExecutionTimeInMilliseconds: 15000
        ));

        $messages = $ecotoneLite->sendQueryWithRouting('getMessages');

        self::assertCount(1, $messages);
        self::assertEquals('exampleData', $messages[0]['payload']);
        self::assertEquals('value', $messages[0]['metadata']['key']);
    }
}
