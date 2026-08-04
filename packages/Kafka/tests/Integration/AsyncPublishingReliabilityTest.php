<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Kafka\Outbound\MessagePublishingException;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class AsyncPublishingReliabilityTest extends TestCase
{
    public function test_broker_rejected_message_fails_synchronous_fallback_of_async_publisher(): void
    {
        $publisher = $this->bootstrapPublisher();

        $this->expectException(PublishingFailedException::class);

        $publisher->send(str_repeat('x', 2_000_000));
    }

    public function test_broker_rejected_message_fails_async_publish_on_future_resolve(): void
    {
        $publisher = $this->bootstrapPublisher();

        $future = $publisher->asyncPublish(str_repeat('x', 2_000_000));

        $this->expectException(PublishingFailedException::class);

        $future->resolve();
    }

    public function test_broker_rejected_message_fails_plain_synchronous_publisher(): void
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(topicName: Uuid::v7()->toRfc4122())
                        ->setConfiguration('message.max.bytes', '4000000'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $publisher = $messaging->getGateway(MessagePublisher::class);

        $this->expectException(MessagePublishingException::class);

        $publisher->send(str_repeat('x', 2_000_000));
    }

    private function bootstrapPublisher(): MessagePublisher
    {
        $messaging = EcotoneLite::bootstrapFlowTesting(
            [],
            [KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaPublisherConfiguration::createWithDefaults(topicName: Uuid::v7()->toRfc4122())
                        ->withAsyncPublishing(timeoutInMilliseconds: 10000)
                        ->setConfiguration('message.max.bytes', '4000000'),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        return $messaging->getGateway(MessagePublisher::class);
    }
}
