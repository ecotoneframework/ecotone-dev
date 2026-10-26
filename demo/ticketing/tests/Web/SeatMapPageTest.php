<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Sales\ConfirmSeats;
use App\Sales\HoldSeat;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class SeatMapPageTest extends WebTestCase
{
    public function test_the_seat_map_page_colours_a_held_and_a_sold_seat_once_the_consumer_has_run_and_refreshes_itself(): void
    {
        $client = $this->seededClient();
        $concertId = $this->concertIdOf('The Midnight Owls');
        $holdingOrder = Uuid::v7()->toRfc4122();
        $this->hold($holdingOrder, $concertId, 'A-1', Uuid::v7()->toRfc4122());
        $buyingOrder = Uuid::v7()->toRfc4122();
        $buyer = Uuid::v7()->toRfc4122();
        $this->hold($buyingOrder, $concertId, 'A-2', $buyer);
        $this->commandBus()->send(new ConfirmSeats(orderId: $buyingOrder, concertId: $concertId, customerId: $buyer, seatIds: [$concertId . '/A-2'], paymentReference: 'pay-1'), metadata: ['concertId' => $concertId]);
        $this->runTheConsumer($client);

        $client->request('GET', '/concerts/' . $concertId . '/seats');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'The Midnight Owls');
        self::assertSelectorExists('meta[http-equiv="refresh"]');
        self::assertSelectorCount(72, '.seat');
        self::assertSelectorCount(1, '.seat-held');
        self::assertSelectorTextContains('.seat-held', 'A-1');
        self::assertSelectorCount(1, '.seat-sold');
        self::assertSelectorTextContains('.seat-sold', 'A-2');
        self::assertSelectorCount(70, '.seat-free');
    }

    public function test_the_admin_concert_page_links_its_seat_map_and_shows_the_sales_ledger(): void
    {
        $client = $this->seededClient();
        $concertId = $this->concertIdOf('The Midnight Owls');
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->hold($orderId, $concertId, 'A-1', $customerId);
        $this->hold($orderId, $concertId, 'C-1', $customerId);
        $this->commandBus()->send(new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-1', $concertId . '/C-1'], paymentReference: 'pay-1'), metadata: ['concertId' => $concertId]);
        $this->runTheConsumer($client);

        $crawler = $client->request('GET', '/admin/concerts/' . $concertId);

        self::assertResponseIsSuccessful();
        self::assertSame('/concerts/' . $concertId . '/seats', $crawler->filter('a.seat-map-link')->attr('href'));
        self::assertSelectorTextContains('table.ledger tbody tr:nth-child(1)', 'standard');
        self::assertSelectorTextContains('table.ledger tbody tr:nth-child(1)', '45.00 EUR');
        self::assertSelectorTextContains('table.ledger tbody tr:nth-child(2)', 'vip');
        self::assertSelectorTextContains('table.ledger tbody tr:nth-child(2)', '95.00 EUR');
        self::assertSelectorTextContains('table.ledger tfoot', '2 of 72 seats sold');
        self::assertSelectorTextContains('table.ledger tfoot', '140.00 EUR');
    }

    public function test_the_seat_map_of_an_unknown_concert_is_not_found_and_points_to_the_concerts(): void
    {
        $client = $this->seededClient();
        $concertId = Uuid::v7()->toRfc4122();

        $client->request('GET', '/concerts/' . $concertId . '/seats');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.refusal', "There is no concert {$concertId}. Pick one from the list below.");
    }

    private function seededClient(): KernelBrowser
    {
        $client = static::createClient();
        /** @var ManagerRegistry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, messenger_messages, concert_seat_map, concert_sales_ledger');
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'demo:seed', '--catalogueOnly' => true]), new NullOutput()));

        return $client;
    }

    private function runTheConsumer(KernelBrowser $client): void
    {
        $application = new Application($client->getKernel());
        $application->setAutoExit(false);
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'ecotone:run', 'consumerName' => 'ticketing_async', '--finishWhenNoMessages' => true, '--stopOnFailure' => true, '--executionTimeLimit' => 30000]), new NullOutput()));
    }

    private function concertIdOf(string $artist): string
    {
        /** @var QueryBus $queryBus */
        $queryBus = static::getContainer()->get(QueryBus::class);

        return array_column($queryBus->sendWithRouting('concerts.list'), 'concertId', 'artist')[$artist];
    }

    private function hold(string $orderId, string $concertId, string $seatCode, string $customerId): void
    {
        $this->commandBus()->send(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/' . $seatCode, customerId: $customerId), metadata: ['concertId' => $concertId]);
    }

    private function commandBus(): CommandBus
    {
        return static::getContainer()->get(CommandBus::class);
    }
}
