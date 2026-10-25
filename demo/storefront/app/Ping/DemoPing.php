<?php

declare(strict_types=1);

namespace App\Ping;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Gateway\DistributedBus;
use Symfony\Component\Uid\Uuid;

final class DemoPing
{
    private const PING_ID_HEADER = 'demo_ping_id';

    #[ConsoleCommand('demo:ping')]
    public function ping(ConsoleWriter $writer, #[Reference] DistributedBus $distributedBus, #[Reference] PongLog $pongLog, int $waitSeconds = 5): void
    {
        $pingId = Uuid::v7()->toRfc4122();
        $distributedBus->sendMessage('ticketing', 'demo.ping', 'storefront', metadata: [self::PING_ID_HEADER => $pingId], replyRoutingKey: 'demo.pong');
        $writer->info(sprintf('Sent demo.ping %s to ticketing, waiting up to %d seconds for the reply.', $pingId, $waitSeconds));

        $deadline = microtime(true) + $waitSeconds;
        do {
            $replies = $pongLog->repliesTo($pingId);
            if ($replies !== []) {
                $writer->success(sprintf('Reply arrived: %s', $replies[0]));

                return;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        $writer->error(sprintf('No reply from ticketing within %d seconds. Check that ticketing-worker consumes channel "ticketing" and storefront-worker consumes channel "storefront" (docker compose -f demo/docker-compose.yml ps), and look for the message in Pulse\'s dead letters.', $waitSeconds));
    }

    #[InternalHandler('demo.pong')]
    public function pong(string $reply, #[Header(self::PING_ID_HEADER)] string $pingId, #[Reference] PongLog $pongLog): void
    {
        $pongLog->record($pingId, $reply);
    }
}
