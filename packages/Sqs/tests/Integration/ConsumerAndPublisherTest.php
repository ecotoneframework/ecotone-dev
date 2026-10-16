<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Ecotone\Api\Sqs\SqsMessagePublisherConfiguration;
use Ecotone\Sqs\Configuration\SqsMessageConsumerConfiguration;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Sqs\ConnectionTestCase;
use Test\Ecotone\Sqs\Fixture\SqsConsumer\SqsConsumerExample;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ConsumerAndPublisherTest extends ConnectionTestCase
{
    public function testing_sending_message_using_publisher_and_receiving_using_consumer()
    {
        $endpointId = 'sqs_consumer';
        $queueName = Uuid::v7()->toRfc4122();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [SqsConsumerExample::class],
            [
                new SqsConsumerExample(),
                SqsConnectionFactory::class => $this->getConnectionFactory(),
            ],
            extensionObjects: [
                SqsMessageConsumerConfiguration::create($endpointId, $queueName),
                SqsMessagePublisherConfiguration::create(queueName:$queueName),
            ],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
        );

        $payload = 'random_payload';
        $messagePublisher = $ecotoneLite->getMessagePublisher();
        $messagePublisher->send($payload);

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));

        $ecotoneLite->run($endpointId, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1)->withExecutionTimeLimitInMilliseconds(1));
        $this->assertEquals([$payload], $ecotoneLite->getQueryBus()->sendWithRouting('consumer.getMessagePayloads'));
    }
}
