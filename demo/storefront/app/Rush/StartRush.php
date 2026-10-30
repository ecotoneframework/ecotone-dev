<?php

declare(strict_types=1);

namespace App\Rush;

use DomainException;

final class StartRush
{
    public const MAX_BUYERS = 200;
    public const MAX_SEATS_PER_BUYER = 4;
    public const DEFAULTS = ['buyers' => 30, 'declinedShare' => 30, 'outageShare' => 10, 'bounceShare' => 10, 'seatsPerBuyer' => 2];

    public function __construct(
        public readonly string $rushId,
        public readonly string $concertId,
        public readonly int $buyers,
        public readonly int $declinedShare,
        public readonly int $outageShare,
        public readonly int $bounceShare,
        public readonly int $seatsPerBuyer,
    ) {
        if ($buyers < 1 || $buyers > self::MAX_BUYERS) {
            throw new DomainException(sprintf('A rush of %d buyers cannot run: a rush sends 1 to %d buyers. Ask for between 1 and %d buyers.', $buyers, self::MAX_BUYERS, self::MAX_BUYERS));
        }
        if ($seatsPerBuyer < 1 || $seatsPerBuyer > self::MAX_SEATS_PER_BUYER) {
            throw new DomainException(sprintf('A rush buyer cannot hold %d seats: ticketing lets a customer hold 1 to %d seats per concert. Ask for between 1 and %d seats per buyer.', $seatsPerBuyer, self::MAX_SEATS_PER_BUYER, self::MAX_SEATS_PER_BUYER));
        }
        foreach (['declined' => $declinedShare, 'outage' => $outageShare, 'bounce' => $bounceShare] as $share => $percent) {
            if ($percent < 0 || $percent > 100) {
                throw new DomainException(sprintf('The %s share of a rush is %d %%, but a share is 0 to 100 %%. Give the declined, outage and bounce shares as whole percents between 0 and 100.', $share, $percent));
            }
        }
        if ($declinedShare + $outageShare + $bounceShare > 100) {
            throw new DomainException(sprintf(
                'The declined (%d %%), outage (%d %%) and bounce (%d %%) shares of a rush add up to %d %%, but each buyer plays one part, so they cannot exceed 100 %%. Lower them until they add up to at most 100 %%; the rest of the buyers pay with a card that succeeds.',
                $declinedShare,
                $outageShare,
                $bounceShare,
                $declinedShare + $outageShare + $bounceShare,
            ));
        }
    }
}
