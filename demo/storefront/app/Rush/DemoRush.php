<?php

declare(strict_types=1);

namespace App\Rush;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ConsoleParameterOption;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Symfony\Component\Uid\Uuid;

final class DemoRush
{
    #[ConsoleCommand('demo:rush')]
    public function rush(
        ConsoleWriter $writer,
        string $concertId,
        #[Reference] CommandBus $commandBus,
        #[Reference] QueryBus $queryBus,
        #[ConsoleParameterOption] int $buyers = 30,
        #[ConsoleParameterOption] int $declinedShare = 30,
        #[ConsoleParameterOption] int $outageShare = 10,
        #[ConsoleParameterOption] int $bounceShare = 10,
        #[ConsoleParameterOption] int $seatsPerBuyer = 2,
    ): void {
        $rushId = Uuid::v7()->toRfc4122();
        $refusal = RushSimulator::refusalOf($queryBus->sendWithRouting('concert.detail', $concertId), $concertId, $rushId);
        if ($refusal !== null) {
            throw $refusal;
        }
        $commandBus->send(new StartRush(
            rushId: $rushId,
            concertId: $concertId,
            buyers: $buyers,
            declinedShare: $declinedShare,
            outageShare: $outageShare,
            bounceShare: $bounceShare,
            seatsPerBuyer: $seatsPerBuyer,
        ));
        $writer->success(sprintf('Started rush %s of %d buyers on concert %s. Follow it on the concert page or with the rush.state query; storefront-worker lets the buyers join a few at a time.', $rushId, $buyers, $concertId));
    }
}
