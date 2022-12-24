<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Sqs\Configuration\SqsMessageConsumerConfiguration;
use Ecotone\Sqs\Configuration\SqsMessagePublisherConfiguration;
use Enqueue\Sqs\SqsConnectionFactory;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Sqs\AbstractConnectionTest;
use Test\Ecotone\Sqs\Fixture\SqsConsumer\SqsConsumerExample;

/**
 * @internal
 */
final class ConsumerAndPublisherTest extends AbstractConnectionTest
{
    public function TODO_testing_sending_message_using_publisher_and_receiving_using_consumer()
    {
        $endpointId = 'sqs_consumer';
        $queueName = Uuid::uuid4()->toString();
        $ecotoneLite = EcotoneLite::bootstrapForTesting(
            [SqsConsumerExample::class],
            [
                new SqsConsumerExample(),
                SqsConnectionFactory::class => $this->getConnectionFactory(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::SQS_PACKAGE]))
                ->withExtensionObjects([
                    SqsMessageConsumerConfiguration::create($endpointId, $queueName),
                    SqsMessagePublisherConfiguration::create(queueName: $queueName),
                ])
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
