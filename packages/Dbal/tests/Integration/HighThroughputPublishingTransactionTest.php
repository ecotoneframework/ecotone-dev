<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PublishingFailedException;
use Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Recoverability\ErrorContext;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\HighThroughputPublishing\HighThroughputTestChannelBuilder;
use Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler\NotificationService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 * @internal
 */
final class HighThroughputPublishingTransactionTest extends DbalMessagingTestCase
{
    public function test_successful_delivery_confirmations_commit_database_transaction(): void
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [HighThroughputTestChannelBuilder::create('notifications')],
            []
        );

        $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));

        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]));
        $this->assertNotNull($ecotoneLite->getMessageChannel('notifications')->receive());
    }

    public function test_failed_delivery_confirmation_rolls_back_database_transaction(): void
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [HighThroughputTestChannelBuilder::create('notifications', deliveryFailureReason: 'broker not available')],
            []
        );

        $deliveryFailed = false;
        try {
            $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));
        } catch (PublishingFailedException) {
            $deliveryFailed = true;
        }
        $this->assertTrue($deliveryFailed);

        $this->expectException(AggregateNotFoundException::class);

        $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
    }

    public function test_failed_delivery_routed_to_error_channel_commits_database_transaction(): void
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [
                HighThroughputTestChannelBuilder::create('notifications', deliveryFailureReason: 'broker not available'),
                SimpleMessageChannelBuilder::createQueueChannel('failure_channel'),
            ],
            [GlobalPollableChannelConfiguration::createWithDefaults()->withErrorChannel('failure_channel')]
        );

        $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));

        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]));

        $failedMessage = $ecotoneLite->receiveMessageFrom('failure_channel');
        $this->assertNotNull($failedMessage);
        $this->assertStringContainsString('broker not available', $failedMessage->getHeaders()->get(ErrorContext::EXCEPTION_MESSAGE));
    }

    private function bootstrapEcotone(array $channelBuilders, array $extensionObjects): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__ . '/../Fixture/ORM/Person'])],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects(array_merge(
                    $extensionObjects,
                    $channelBuilders,
                    [
                        DbalConfiguration::createWithDefaults()
                            ->withTransactionOnCommandBus(true)
                            ->withTransactionOnAsynchronousEndpoints(true)
                            ->withDoctrineORMRepositories(true),
                    ]
                )),
            addInMemoryStateStoredRepository: false
        );
    }
}
