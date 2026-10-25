<?php

declare(strict_types=1);

namespace Tests\Concert;

use App\Concert\ConcertCatalogue;
use App\Concert\ConcertProjector;
use App\Infrastructure\EcotoneConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ConcertCatalogueTest extends TestCase
{
    private const NIGHT_CONCERT = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e40';
    private const MORNING_CONCERT = '0199f3a8-6f2a-7c1e-9a51-3d2f6c1b8e41';

    private array $channels;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection((new DsnParser(['pgsql' => 'pdo_pgsql']))->parse(getenv('DATABASE_DSN')));
        $connection->executeStatement('DELETE FROM concerts');
        $connection->close();
        $this->channels = [
            SimpleMessageChannelBuilder::createQueueChannel('storefront'),
            SimpleMessageChannelBuilder::createQueueChannel('ticketing'),
            SimpleMessageChannelBuilder::createQueueChannel('storefront_async'),
            SimpleMessageChannelBuilder::createQueueChannel('ecotone_pulse'),
        ];
    }

    public function test_a_scheduled_concert_is_listed_as_scheduled(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'concertId' => self::NIGHT_CONCERT,
                'artist' => 'The Night Owls',
                'venueName' => 'Ecotone Hall',
                'startsAt' => '2026-12-01T20:00:00+00:00',
                'currency' => 'EUR',
                'standardPriceCents' => 4500,
                'vipPriceCents' => 12000,
                'status' => 'scheduled',
            ]],
            $storefront->sendQueryWithRouting('concerts.list'),
        );
    }

    public function test_the_concert_detail_groups_its_seats_into_tier_sections_and_rows(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        $concert = $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT);

        self::assertSame('The Night Owls', $concert['artist']);
        self::assertSame(
            [
                ['tier' => 'vip', 'rows' => [
                    ['row' => 'A', 'seats' => [
                        ['seatId' => self::NIGHT_CONCERT . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'],
                        ['seatId' => self::NIGHT_CONCERT . '/A-2', 'seatCode' => 'A-2', 'tier' => 'vip'],
                    ]],
                ]],
                ['tier' => 'standard', 'rows' => [
                    ['row' => 'B', 'seats' => [
                        ['seatId' => self::NIGHT_CONCERT . '/B-1', 'seatCode' => 'B-1', 'tier' => 'standard'],
                        ['seatId' => self::NIGHT_CONCERT . '/B-2', 'seatCode' => 'B-2', 'tier' => 'standard'],
                        ['seatId' => self::NIGHT_CONCERT . '/B-10', 'seatCode' => 'B-10', 'tier' => 'standard'],
                    ]],
                    ['row' => 'C', 'seats' => [
                        ['seatId' => self::NIGHT_CONCERT . '/C-1', 'seatCode' => 'C-1', 'tier' => 'standard'],
                    ]],
                ]],
            ],
            $concert['sections'],
        );
    }

    public function test_an_unknown_concert_has_no_detail(): void
    {
        self::assertNull($this->storefront()->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT));
    }

    public function test_opened_sales_put_the_concert_on_sale_and_list_it_before_concerts_not_on_sale(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::MORNING_CONCERT, artist: 'Early Birds', startsAt: '2026-11-01T09:00:00+00:00'));
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[self::NIGHT_CONCERT, 'on_sale'], [self::MORNING_CONCERT, 'scheduled']],
            array_map(fn (array $concert) => [$concert['concertId'], $concert['status']], $storefront->sendQueryWithRouting('concerts.list')),
        );
    }

    public function test_a_cancelled_concert_is_marked_cancelled(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.cancelled', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('cancelled', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_a_redelivered_schedule_keeps_one_concert(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertCount(1, $storefront->sendQueryWithRouting('concerts.list'));
    }

    public function test_opening_sales_for_a_concert_never_scheduled_is_refused(): void
    {
        $storefront = $this->storefront();
        $this->publish($this->ticketing(), 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);

        $this->expectExceptionMessage(sprintf('Concert %s is not in the storefront catalogue, so concert.sales_opened cannot put it on sale. A concert enters the catalogue with concert.scheduled, which has to arrive first: publish concert.scheduled for this concert again from ticketing, then replay this concert.sales_opened message from the dbal_dead_letter with php artisan ecotone:deadletter:replay <messageId> (ecotone:deadletter:list shows the id), or replay every dead letter with php artisan ecotone:deadletter:replayAll.', self::NIGHT_CONCERT));

        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_cancelling_a_concert_never_scheduled_is_refused(): void
    {
        $storefront = $this->storefront();
        $this->publish($this->ticketing(), 'concert.cancelled', ['concertId' => self::NIGHT_CONCERT]);

        $this->expectExceptionMessage(sprintf('Concert %s is not in the storefront catalogue, so concert.cancelled cannot cancel it. A concert enters the catalogue with concert.scheduled, which has to arrive first: publish concert.scheduled for this concert again from ticketing, then replay this concert.cancelled message from the dbal_dead_letter with php artisan ecotone:deadletter:replay <messageId> (ecotone:deadletter:list shows the id), or replay every dead letter with php artisan ecotone:deadletter:replayAll.', self::NIGHT_CONCERT));

        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_sales_opened_before_the_concert_is_scheduled_goes_on_sale_once_replayed_after_the_schedule(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();
        $deadLetter = $storefront->getGateway(DeadLetterGateway::class);
        $deadLetter->deleteAll();

        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
        $deadLetter->replayAll();
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('on_sale', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
        self::assertSame(0, $deadLetter->count());
    }

    public function test_sales_opened_again_after_cancellation_keeps_the_concert_cancelled(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.cancelled', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('cancelled', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_sales_opened_again_keeps_the_concert_on_sale(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('on_sale', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_a_redelivered_schedule_keeps_the_concert_on_sale(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('on_sale', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_a_redelivered_schedule_keeps_the_concert_cancelled(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.cancelled', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('cancelled', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_a_redelivered_schedule_with_changed_details_updates_the_concert(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Late Owls', startsAt: '2026-12-02T21:30:00+00:00', tiers: ['standard' => 5000, 'vip' => 15000]));
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(
            [[
                'concertId' => self::NIGHT_CONCERT,
                'artist' => 'The Late Owls',
                'venueName' => 'Ecotone Hall',
                'startsAt' => '2026-12-02T21:30:00+00:00',
                'currency' => 'EUR',
                'standardPriceCents' => 5000,
                'vipPriceCents' => 15000,
                'status' => 'scheduled',
            ]],
            $storefront->sendQueryWithRouting('concerts.list'),
        );
    }

    public function test_changed_prices_update_the_tier_prices_of_the_concert(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.prices_changed', ['concertId' => self::NIGHT_CONCERT, 'tiers' => ['standard' => 5500, 'vip' => 14000]]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        $concert = $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT);
        self::assertSame([5500, 14000], [$concert['standardPriceCents'], $concert['vipPriceCents']]);
    }

    public function test_changing_prices_of_a_concert_never_scheduled_is_refused(): void
    {
        $storefront = $this->storefront();
        $this->publish($this->ticketing(), 'concert.prices_changed', ['concertId' => self::NIGHT_CONCERT, 'tiers' => ['standard' => 5500, 'vip' => 14000]]);

        $this->expectExceptionMessage(sprintf('Concert %s is not in the storefront catalogue, so concert.prices_changed cannot change its prices. A concert enters the catalogue with concert.scheduled', self::NIGHT_CONCERT));

        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public function test_a_sold_out_concert_is_marked_sold_out(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sold_out', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('sold_out', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_sold_out_again_keeps_the_concert_sold_out(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sold_out', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sold_out', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sales_opened', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('sold_out', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_sold_out_after_cancellation_keeps_the_concert_cancelled(): void
    {
        $storefront = $this->storefront();
        $ticketing = $this->ticketing();

        $this->publish($ticketing, 'concert.scheduled', $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00'));
        $this->publish($ticketing, 'concert.cancelled', ['concertId' => self::NIGHT_CONCERT]);
        $this->publish($ticketing, 'concert.sold_out', ['concertId' => self::NIGHT_CONCERT]);
        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame('cancelled', $storefront->sendQueryWithRouting('concert.detail', self::NIGHT_CONCERT)['status']);
    }

    public function test_selling_out_a_concert_never_scheduled_is_refused(): void
    {
        $storefront = $this->storefront();
        $this->publish($this->ticketing(), 'concert.sold_out', ['concertId' => self::NIGHT_CONCERT]);

        $this->expectExceptionMessage(sprintf('Concert %s is not in the storefront catalogue, so concert.sold_out cannot mark it sold out. A concert enters the catalogue with concert.scheduled', self::NIGHT_CONCERT));

        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    #[DataProvider('seatCodesThatAreNotRowAndNumber')]
    public function test_a_schedule_with_a_seat_code_that_is_not_row_and_number_is_refused(string $seatCode): void
    {
        $storefront = $this->storefront();
        $scheduled = $this->scheduled(self::NIGHT_CONCERT, artist: 'The Night Owls', startsAt: '2026-12-01T20:00:00+00:00');
        $scheduled['seats'][] = ['seatId' => self::NIGHT_CONCERT . '/' . $seatCode, 'seatCode' => $seatCode, 'tier' => 'vip'];
        $this->publish($this->ticketing(), 'concert.scheduled', $scheduled);

        $this->expectExceptionMessage(sprintf('concert.scheduled for concert %s names seat code "%s", which is not ROW-NUMBER like "A-1", so the storefront cannot place the seat in its row.', self::NIGHT_CONCERT, $seatCode));

        $storefront->run('storefront', ExecutionPollingMetadata::createWithTestingSetup());
    }

    public static function seatCodesThatAreNotRowAndNumber(): iterable
    {
        yield 'no dash' => ['A3'];
        yield 'trailing newline' => ["A-3\n"];
    }

    private function scheduled(string $concertId, string $artist, string $startsAt, array $tiers = ['standard' => 4500, 'vip' => 12000]): array
    {
        return [
            'concertId' => $concertId,
            'venueName' => 'Ecotone Hall',
            'artist' => $artist,
            'startsAt' => $startsAt,
            'currency' => 'EUR',
            'tiers' => $tiers,
            'seats' => [
                ['seatId' => $concertId . '/B-10', 'seatCode' => 'B-10', 'tier' => 'standard'],
                ['seatId' => $concertId . '/A-2', 'seatCode' => 'A-2', 'tier' => 'vip'],
                ['seatId' => $concertId . '/C-1', 'seatCode' => 'C-1', 'tier' => 'standard'],
                ['seatId' => $concertId . '/A-1', 'seatCode' => 'A-1', 'tier' => 'vip'],
                ['seatId' => $concertId . '/B-2', 'seatCode' => 'B-2', 'tier' => 'standard'],
                ['seatId' => $concertId . '/B-1', 'seatCode' => 'B-1', 'tier' => 'standard'],
            ],
        ];
    }

    private function publish(FlowTestSupport $ticketing, string $routingKey, array $payload): void
    {
        $ticketing->getGateway(DistributedBus::class)->convertAndPublishEvent($routingKey, $payload, metadata: ['concertId' => $payload['concertId']]);
    }

    private function storefront(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [EcotoneConfiguration::class, ConcertProjector::class, ConcertCatalogue::class],
            containerOrAvailableServices: [new EcotoneConfiguration(), new ConcertProjector(), DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: $this->channels,
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function ticketing(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [],
            containerOrAvailableServices: [],
            extensionObjects: [
                ...$this->channels,
                ServiceConfiguration::createWithDefaults()->withServiceName('ticketing'),
                DistributedServiceMap::initialize()->withEventMapping(channelName: 'storefront', subscriptionKeys: ['concert.*']),
            ],
            pathToRootCatalog: dirname(__DIR__, 2),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
