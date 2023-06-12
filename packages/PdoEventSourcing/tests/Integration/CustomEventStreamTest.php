<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Doctrine\DBAL\Driver\PDO\PgSQL\Driver;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\EventSourcing\Prooph\FromProophMessageToArrayConverter;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlSingleStreamStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy\PostgresSingleStreamStrategy;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\CustomEventStream\CustomEventStreamProjection;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class CustomEventStreamTest extends EventSourcingMessagingTestCase
{
    public function test_handling_custom_event_stream_when_custom_stream_persistence_is_enabled(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new CustomEventStreamProjection(), new BasketEventConverter(), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::AMQP_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\CustomEventStream',
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withCustomPersistenceStrategy(
                            $this->isPostgres()
                                ? new PostgresSingleStreamStrategy(new FromProophMessageToArrayConverter())
                                : new MySqlSingleStreamStrategy(new FromProophMessageToArrayConverter())
                        ),
                ]),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $ecotone
            ->sendCommand(new CreateBasket('2000'))
            ->sendCommand(new CreateBasket('2001'))
        ;

        self::assertEquals(2, $ecotone->sendQueryWithRouting('action_collector.getCount'));
    }

    private function isPostgres(): bool
    {
        return $this->getConnection()->getDriver() instanceof Driver;
    }
}
