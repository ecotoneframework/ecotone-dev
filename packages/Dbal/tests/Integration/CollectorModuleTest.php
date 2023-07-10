<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Channel\Collector\Config\CollectorConfiguration;
use Ecotone\Messaging\Channel\ExceptionalQueueChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler\NotificationService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

final class CollectorModuleTest extends DbalMessagingTestCase
{
    public function test_failure_during_sending_should_not_affect_transaction()
    {
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            'customErrorChannel',
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push'),
                ExceptionalQueueChannel::createWithExceptionOnSend('customErrorChannel'),
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push'),
            ]
        );

        $exception = false;
        try {
            $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));
        }catch (\RuntimeException) {$exception = true;}
        $this->assertTrue($exception);

        $this->assertSame(
            'Johny',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    /**
     * @TODO
     */
    public function test_failure_during_serialization_should_rollback_transaction()
    {
        $this->markTestSkipped("Not implemented yet");
        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            'errorChannel',
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                DbalBackedMessageChannelBuilder::create('notifications')
            ],
            [
                CollectorConfiguration::createWithDefaultProxy(['notifications']),
            ]
        );


        $exception = false;
        try {
            $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));
        }catch (\InvalidArgumentException) {$exception = true;}
        $this->assertTrue($exception);

        $this->expectException(AggregateNotFoundException::class);

        $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
    }

    public function test_collected_message_is_pushed_to_dead_letter_and_replayed_with_success()
    {
        $this->markTestIncomplete('to finish');

        $ecotoneLite = $this->bootstrapEcotone(
            [Person::class, NotificationService::class],
            [new NotificationService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            'errorChannel',
            [
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
                SimpleMessageChannelBuilder::createQueueChannel('notifications'),
                ExceptionalQueueChannel::createWithExceptionOnSend('push'),
                ExceptionalQueueChannel::createWithExceptionOnSend('customErrorChannel'),
            ],
            [
                CollectorConfiguration::createWithOutboundChannel(['notifications'], 'push'),
            ]
        );

        $exception = false;
        try {
            $ecotoneLite->sendCommand(new RegisterPerson(100, 'Johny'));
        }catch (\RuntimeException) {$exception = true;}
        $this->assertTrue($exception);

        $this->assertSame(
            'Johny',
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    /**
     * @param string[] $classesToResolve
     * @param object[] $services
     * @param MessageChannelBuilder[] $channelBuilders
     * @param CollectorConfiguration[] $extensionObjects
     */
    private function bootstrapEcotone(array $classesToResolve, array $services, string $errorChannel, array $channelBuilders, array $extensionObjects): FlowTestSupport
    {
        $this->setupUserTable();

        return EcotoneLite::bootstrapFlowTesting(
            $classesToResolve,
            $services,
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withDefaultErrorChannel($errorChannel)
                ->withDefaultSerializationMediaType('application/json')
                ->withExtensionObjects(array_merge(
                    $extensionObjects,
                    $channelBuilders,
                    [
                        DbalConfiguration::createWithDefaults()
                            ->withTransactionOnCommandBus(true)
                            ->withTransactionOnAsynchronousEndpoints(true)
                            ->withDoctrineORMRepositories(true)
                    ]
                )),
            addInMemoryStateStoredRepository: false
        );
    }
}