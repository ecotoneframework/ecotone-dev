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
final class TicketPageTest extends WebTestCase
{
    public function test_the_ticket_page_shows_the_concert_seat_tier_and_entry_code_of_an_issued_ticket(): void
    {
        $client = $this->seededClient();
        $concertId = $this->concertIdOf('The Midnight Owls');
        $orderId = Uuid::v7()->toRfc4122();
        $customerId = Uuid::v7()->toRfc4122();
        $this->commandBus()->send(new HoldSeat(orderId: $orderId, concertId: $concertId, seatId: $concertId . '/A-3', customerId: $customerId), metadata: ['concertId' => $concertId]);
        $this->commandBus()->send(new ConfirmSeats(orderId: $orderId, concertId: $concertId, customerId: $customerId, seatIds: [$concertId . '/A-3'], paymentReference: 'pay-1'), metadata: ['concertId' => $concertId]);
        $this->runTheConsumer($client);
        $ticket = $this->queryBus()->sendWithRouting('tickets.of_order', $orderId)[0];

        $client->request('GET', '/tickets/' . $ticket['ticketNumber']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'The Midnight Owls');
        self::assertSelectorTextContains('.ticket-number', $ticket['ticketNumber']);
        self::assertSelectorTextContains('.seat-code', 'A-3');
        self::assertSelectorTextContains('.tier', 'vip');
        self::assertSelectorTextContains('.entry-code', $ticket['entryCode']);
        self::assertSelectorTextContains('.order-id', $orderId);
    }

    public function test_the_page_of_an_unknown_ticket_is_not_found_and_says_where_ticket_numbers_come_from(): void
    {
        $client = $this->seededClient();
        $ticketNumber = Uuid::v7()->toRfc4122();

        $client->request('GET', '/tickets/' . $ticketNumber);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('.refusal', "There is no ticket {$ticketNumber}. Ticket numbers are issued once an order's seats are sold; open the ticket from the order page of the storefront.");
    }

    private function seededClient(): KernelBrowser
    {
        $client = static::createClient();
        /** @var ManagerRegistry $doctrine */
        $doctrine = static::getContainer()->get('doctrine');
        $doctrine->getConnection()->executeStatement('TRUNCATE concerts, venues, messenger_messages, concert_seat_map, concert_sales_ledger, issued_tickets');
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
        return array_column($this->queryBus()->sendWithRouting('concerts.list'), 'concertId', 'artist')[$artist];
    }

    private function commandBus(): CommandBus
    {
        return static::getContainer()->get(CommandBus::class);
    }

    private function queryBus(): QueryBus
    {
        return static::getContainer()->get(QueryBus::class);
    }
}
