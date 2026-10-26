<?php

declare(strict_types=1);

namespace App\Issuance;

use App\Sales\SoldSeat;
use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ConfigurationVariable;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Orchestrator;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Messaging\MessageHeaders;
use Symfony\Component\Uid\Uuid;

final class TicketIssuance
{
    public const INPUT_CHANNEL = 'ticket.issuance';

    private const ENTRY_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly IssuedTicketRegistry $registry)
    {
    }

    /**
     * @return list<string>
     */
    #[Orchestrator(inputChannelName: self::INPUT_CHANNEL, endpointId: 'ticket_issuance')]
    public function slipFor(IssueTickets $command): array
    {
        return [
            'ticket.allocate_numbers',
            ...($command->holdsVipSeat() ? ['ticket.vip_lounge_pass'] : []),
            'ticket.render',
            'ticket.register_entry_codes',
            'ticket.announce',
        ];
    }

    #[InternalHandler(inputChannelName: 'ticket.allocate_numbers')]
    public function allocateNumbers(IssueTickets $command): IssuedTickets
    {
        $issuedTickets = new IssuedTickets(orderId: $command->orderId, concertId: $command->concertId, customerId: $command->customerId, tickets: []);
        $alreadyIssued = $this->registry->ticketsOfOrder($command->orderId);
        if ($alreadyIssued !== []) {
            return $issuedTickets->withTickets($alreadyIssued);
        }

        return $issuedTickets->withTickets(array_map(
            static fn (SoldSeat $seat): Ticket => new Ticket(ticketNumber: Uuid::v7()->toRfc4122(), seatId: $seat->seatId, tier: $seat->tier, entryCode: self::randomEntryCode(), vipLoungePass: false),
            $command->seats,
        ));
    }

    #[InternalHandler(inputChannelName: 'ticket.vip_lounge_pass')]
    public function grantVipLoungePasses(IssuedTickets $issuedTickets): IssuedTickets
    {
        return $issuedTickets->withVipLoungePasses();
    }

    #[Asynchronous('ticketing_async')]
    #[InternalHandler(inputChannelName: 'ticket.render', endpointId: 'render_ticket_files')]
    public function renderTickets(
        IssuedTickets $issuedTickets,
        QueryBus $queryBus,
        #[ConfigurationVariable('ticketFilesDirectory')] string $ticketFilesDirectory,
        #[ConfigurationVariable('ticketingRenderFailsForSeat')] string $renderFailsForSeat,
        #[Header(MessageHeaders::MESSAGE_ID)] string $messageId,
    ): IssuedTickets {
        foreach ($issuedTickets->tickets as $ticket) {
            if ($renderFailsForSeat !== '' && $ticket->seatCode() === $renderFailsForSeat) {
                throw TicketRenderFailure::simulatedFor(seatCode: $renderFailsForSeat, messageId: $messageId);
            }
        }
        $concert = $queryBus->sendWithRouting('concert.detail', metadata: ['aggregate.id' => $issuedTickets->concertId]);
        if (! is_dir($ticketFilesDirectory)) {
            mkdir($ticketFilesDirectory, 0o775, true);
        }
        foreach ($issuedTickets->tickets as $ticket) {
            file_put_contents($ticketFilesDirectory . '/' . $ticket->ticketNumber . '.txt', implode(PHP_EOL, [
                sprintf('Ticket %s', $ticket->ticketNumber),
                sprintf('%s at %s, %s UTC', $concert['artist'], $concert['venueName'], (new DateTimeImmutable($concert['startsAt']))->format('Y-m-d H:i')),
                sprintf('Seat: %s', $ticket->seatCode()),
                sprintf('Tier: %s', $ticket->tier->value),
                ...($ticket->vipLoungePass ? ['VIP lounge pass: included'] : []),
                sprintf('Entry code: %s', $ticket->entryCode),
                sprintf('Order: %s', $issuedTickets->orderId),
                '',
            ]));
        }

        return $issuedTickets;
    }

    #[InternalHandler(inputChannelName: 'ticket.register_entry_codes')]
    public function registerEntryCodes(IssuedTickets $issuedTickets, EcotoneClockInterface $clock): IssuedTickets
    {
        $this->registry->register($issuedTickets, $clock->now());

        return $issuedTickets->withTickets($this->registry->ticketsOfOrder($issuedTickets->orderId));
    }

    #[InternalHandler(inputChannelName: 'ticket.announce')]
    public function announceIssuedTickets(IssuedTickets $issuedTickets, DistributedBus $distributedBus): void
    {
        $distributedBus->convertAndPublishEvent(
            'tickets.issued',
            [
                'orderId' => $issuedTickets->orderId,
                'concertId' => $issuedTickets->concertId,
                'customerId' => $issuedTickets->customerId,
                'tickets' => array_map(
                    static fn (Ticket $ticket): array => ['ticketNumber' => $ticket->ticketNumber, 'seatId' => $ticket->seatId, 'tier' => $ticket->tier->value, 'entryCode' => $ticket->entryCode],
                    $issuedTickets->tickets,
                ),
            ],
            metadata: ['concertId' => $issuedTickets->concertId],
        );
    }

    private static function randomEntryCode(): string
    {
        $entryCode = '';
        for ($character = 0; $character < 8; $character++) {
            $entryCode .= self::ENTRY_CODE_ALPHABET[random_int(0, strlen(self::ENTRY_CODE_ALPHABET) - 1)];
        }

        return $entryCode;
    }
}
