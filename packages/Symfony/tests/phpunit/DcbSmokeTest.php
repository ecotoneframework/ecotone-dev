<?php

declare(strict_types=1);

namespace Test;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Dbal\DbalEventStore;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Symfony\App\DcbSmoke\IssueCoupon;
use Symfony\App\DcbSmoke\Kernel;
use Symfony\App\DcbSmoke\RedeemCoupon;

/**
 * licence Enterprise
 * @internal
 */
final class DcbSmokeTest extends TestCase
{
    public function setUp(): void
    {
        putenv('SYMFONY_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
    }

    protected function tearDown(): void
    {
        putenv('SYMFONY_LICENCE_KEY');
        restore_exception_handler();
    }

    public function test_a_tagged_event_is_appended_and_a_decision_model_is_injected_into_a_handler(): void
    {
        require_once __DIR__ . '/DcbSmoke/src/Kernel.php';

        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();
        $container->get(CacheClearer::class)->clear('');

        /** @var DbalConnectionFactory $connectionFactory */
        $connectionFactory = $container->get(DbalConnectionFactory::class);
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $this->dropTables($connection);

        /** @var CommandBus $commandBus */
        $commandBus = $container->get(CommandBus::class);
        $commandBus->send(new IssueCoupon('SUMMER24', 1));
        $commandBus->send(new RedeemCoupon('SUMMER24'));

        /** @var DbalEventStore $eventStore */
        $eventStore = $container->get(DbalEventStore::class);
        $loaded = $eventStore->loadByCriteria(EventCriteria::tag('coupon', 'SUMMER24'));

        self::assertCount(2, $loaded->events);

        $this->expectExceptionMessage('Coupon SUMMER24 is exhausted');
        $commandBus->send(new RedeemCoupon('SUMMER24'));
    }

    private function dropTables(Connection $connection): void
    {
        foreach (['ecotone_tagged_events', 'ecotone_tag_versions', 'ecotone_event_stream'] as $tableName) {
            if ($connection->createSchemaManager()->tablesExist([$tableName])) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}
