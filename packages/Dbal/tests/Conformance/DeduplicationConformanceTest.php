<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DeduplicationConformanceTest extends DbalMessagingTestCase
{
    private const ONE_HOUR_IN_MILLISECONDS = 3_600_000;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_a_message_handled_once_is_skipped_when_it_arrives_again(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone
            ->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('deduplication.order', 'tea', metadata: [MessageHeaders::MESSAGE_ID => 'm-2']);

        self::assertSame(['coffee', 'tea'], $ecotone->sendQueryWithRouting('deduplication.orders'));
    }

    #[DataProvider('implementations')]
    public function test_the_same_message_is_handled_once_by_each_handler(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone
            ->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('deduplication.invoice', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('deduplication.invoice', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['coffee'], $ecotone->sendQueryWithRouting('deduplication.orders'));
        self::assertSame(['coffee'], $ecotone->sendQueryWithRouting('deduplication.invoices'));
    }

    #[DataProvider('implementations')]
    public function test_a_message_whose_handling_failed_is_handled_when_it_arrives_again(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        try {
            $ecotone->sendCommandWithRouting('deduplication.order', 'fail once', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);
            self::fail('Expected the first handling to fail');
        } catch (RuntimeException) {
        }

        $ecotone->sendCommandWithRouting('deduplication.order', 'fail once', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['fail once'], $ecotone->sendQueryWithRouting('deduplication.orders'));
    }

    #[DataProvider('implementations')]
    public function test_a_message_is_deduplicated_by_its_expression_rather_than_its_id(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone
            ->sendCommandWithRouting('deduplication.ship', 'parcel', metadata: ['shipmentId' => 's-1'])
            ->sendCommandWithRouting('deduplication.ship', 'parcel again', metadata: ['shipmentId' => 's-1'])
            ->sendCommandWithRouting('deduplication.ship', 'letter', metadata: ['shipmentId' => 's-2']);

        self::assertSame(['parcel', 'letter'], $ecotone->sendQueryWithRouting('deduplication.shipments'));
    }

    #[DataProvider('implementations')]
    public function test_a_handled_message_is_handled_again_once_its_record_has_expired_and_was_removed(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        $ecotone
            ->advanceTimeBy(Duration::milliseconds(self::ONE_HOUR_IN_MILLISECONDS + 1))
            ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
            ->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['coffee', 'coffee'], $ecotone->sendQueryWithRouting('deduplication.orders'));
    }

    #[DataProvider('implementations')]
    public function test_removing_expired_records_keeps_the_ones_that_have_not_expired(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);
        $ecotone->advanceTimeBy(Duration::milliseconds(self::ONE_HOUR_IN_MILLISECONDS - 1000));
        $ecotone->sendCommandWithRouting('deduplication.order', 'tea', metadata: [MessageHeaders::MESSAGE_ID => 'm-2']);

        $ecotone
            ->advanceTimeBy(Duration::milliseconds(2000))
            ->runConsoleCommand('ecotone:deduplication:remove-expired-messages', [])
            ->sendCommandWithRouting('deduplication.order', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('deduplication.order', 'tea', metadata: [MessageHeaders::MESSAGE_ID => 'm-2']);

        self::assertSame(['coffee', 'tea', 'coffee'], $ecotone->sendQueryWithRouting('deduplication.orders'));
    }

    private function bootstrap(string $implementation): FlowTestSupport
    {
        $handlers = new class () {
            private array $orders = [];
            private array $invoices = [];
            private array $shipments = [];
            private array $failedOnce = [];

            #[Deduplicated]
            #[CommandHandler('deduplication.order', endpointId: 'deduplication.order.endpoint')]
            public function order(string $order): void
            {
                if ($order === 'fail once' && ! isset($this->failedOnce[$order])) {
                    $this->failedOnce[$order] = true;

                    throw new RuntimeException('Failing the first handling');
                }

                $this->orders[] = $order;
            }

            #[Deduplicated]
            #[CommandHandler('deduplication.invoice', endpointId: 'deduplication.invoice.endpoint')]
            public function invoice(string $order): void
            {
                $this->invoices[] = $order;
            }

            #[Deduplicated(expression: "headers['shipmentId']")]
            #[CommandHandler('deduplication.ship', endpointId: 'deduplication.ship.endpoint')]
            public function ship(string $shipment): void
            {
                $this->shipments[] = $shipment;
            }

            #[QueryHandler('deduplication.orders')]
            public function orders(): array
            {
                return $this->orders;
            }

            #[QueryHandler('deduplication.invoices')]
            public function invoices(): array
            {
                return $this->invoices;
            }

            #[QueryHandler('deduplication.shipments')]
            public function shipments(): array
            {
                return $this->shipments;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withAutomaticTableInitialization(true)
                        ->withDeduplication(expirationTime: self::ONE_HOUR_IN_MILLISECONDS, inMemory: match ($implementation) {
                            'in-memory' => true,
                            'dbal' => false,
                        }),
                ]),
        )->initializeDatabase();
    }
}
