<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\Collector\Config\CollectorConfiguration;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler\NotificationService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CollectorModuleTest extends DbalMessagingTestCase
{
    public function test_no_failure_during_sending_should_commit_transaction_and_send_messages()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
            ],
            [
                PollableChannelConfiguration::neverRetry('notifications')->withCollector(true),
            ]
        );

        $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));

        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]));
        $this->assertNotNull($ecotoneLite->getMessageChannel('notifications')->receive());
    }

    public function test_failure_during_sending_should_rollback_transaction()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('notifications'),
            ],
            [
                PollableChannelConfiguration::neverRetry('notifications')->withCollector(true),
            ]
        );

        $exception = false;
        try {
            $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));
        } catch (RuntimeException) {
            $exception = true;
        }
        $this->assertTrue($exception);

        $this->expectException(AggregateNotFoundException::class);

        $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
    }

    public function test_sending_to_dead_letter_should_not_rollback_transaction()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('notifications'),
            ],
            [
                PollableChannelConfiguration::neverRetry('notifications')
                    ->withErrorChannel('dbal_dead_letter')
                    ->withCollector(true),
            ]
        );

        $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));

        $this->assertNotNull(
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    public function test_replaying_message_sent_error_message_from_dead_letter()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                ExceptionalQueueChannel::createWithExceptionOnSend('notifications', stopFailingAfterAttempt: 1),
            ],
            [
                PollableChannelConfiguration::neverRetry('notifications')
                    ->withErrorChannel('dbal_dead_letter')
                    ->withCollector(true),
            ]
        );

        $messageId = Uuid::uuid4()->toString();
        $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));

        $ecotoneLite->run('notifications');
        $this->assertFalse($ecotoneLite->sendQueryWithRouting('notification.isNotified'));

        /** @var DeadLetterGateway $deadLetterGateway */
        $deadLetterGateway = $ecotoneLite->getGateway(DeadLetterGateway::class);
        foreach ($deadLetterGateway->list(1, 0) as $errorContext) {
            $deadLetterGateway->reply($errorContext->getMessageId());
        }
        $this->assertFalse($ecotoneLite->sendQueryWithRouting('notification.isNotified'));

        $ecotoneLite->run('notifications');
        $this->assertTrue($ecotoneLite->sendQueryWithRouting('notification.isNotified'));
    }

    /**
     * @param string[] $classesToResolve
     * @param object[] $services
     * @param MessageChannelBuilder[] $channelBuilders
     * @param CollectorConfiguration[] $extensionObjects
     */
    private function bootstrapEcotone(array $classesToResolve, array $services, array $channelBuilders, array $extensionObjects): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $services,
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
