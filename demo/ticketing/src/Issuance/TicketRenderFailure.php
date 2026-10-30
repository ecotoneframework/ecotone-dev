<?php

declare(strict_types=1);

namespace App\Issuance;

use RuntimeException;

final class TicketRenderFailure extends RuntimeException
{
    public static function simulatedFor(string $seatCode, string $messageId): self
    {
        return new self(sprintf(
            'Ticket of seat %s cannot be rendered, because TICKETING_RENDER_FAILS_FOR_SEAT is set to %s to simulate a failing render. Unset TICKETING_RENDER_FAILS_FOR_SEAT and recreate the container (docker compose up -d ticketing-worker), because a restart keeps the old environment, then replay this message from the dead letters in Ecotone Pulse or with bin/console ecotone:deadletter:replay %s.',
            $seatCode,
            $seatCode,
            $messageId,
        ));
    }

    public static function directoryCannotBeCreated(string $directory, string $messageId): self
    {
        return new self(sprintf(
            'Tickets cannot be rendered, because the ticket directory %s cannot be created. Make it writable for the worker, or point ticketFilesDirectory in config/services.yaml at a writable directory, then replay this message from the dead letters in Ecotone Pulse or with bin/console ecotone:deadletter:replay %s.',
            $directory,
            $messageId,
        ));
    }
}
