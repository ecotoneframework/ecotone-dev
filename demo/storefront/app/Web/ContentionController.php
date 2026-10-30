<?php

declare(strict_types=1);

namespace App\Web;

use App\Rush\ContentionSimulator;
use App\Rush\StartContention;
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\Uuid;

final class ContentionController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    public function start(Request $request, string $concertId): JsonResponse
    {
        $concert = $this->queryBus->sendWithRouting('concert.detail', $concertId);
        abort_if($concert === null, 404, 'There is no such concert. Pick one from /concerts.');
        $seatId = (string) $request->input('seatId');
        $refusal = ContentionSimulator::refusalOf(concert: $concert, concertId: $concertId, seatId: $seatId, seatStates: $this->queryBus->sendWithRouting('concert.seat_states', ['concertId' => $concertId, 'excludingOrderId' => null]));
        if ($refusal !== null) {
            return response()->json(['error' => $refusal->getMessage()], 409);
        }
        $buyers = (string) $request->input('buyers', StartContention::DEFAULT_BUYERS);
        if (filter_var($buyers, FILTER_VALIDATE_INT) === false) {
            return response()->json(['error' => sprintf('The buyers of a contention is "%s", not a whole number, so the contention cannot start. Give buyers as a whole number between %d and %d, or leave it out to send %d.', $buyers, StartContention::MIN_BUYERS, StartContention::MAX_BUYERS, StartContention::DEFAULT_BUYERS)], 422);
        }
        try {
            $command = new StartContention(contentionId: Uuid::v7()->toRfc4122(), concertId: $concertId, seatId: $seatId, buyers: (int) $buyers);
        } catch (DomainException $refusal) {
            return response()->json(['error' => $refusal->getMessage()], 422);
        }
        $this->commandBus->send($command);

        return response()->json([
            'contentionId' => $command->contentionId,
            'pageUrl' => url('/contentions/' . $command->contentionId),
            'stateUrl' => url('/contentions/' . $command->contentionId . '/state.json'),
        ], 201);
    }

    public function show(string $contentionId): View
    {
        $contention = $this->contention($contentionId);

        return view('contentions.show', [
            'contention' => $contention,
            'concert' => $this->queryBus->sendWithRouting('concert.detail', $contention['concertId']),
            'jaegerUrl' => config('demo.jaeger_url') . '/search?service=ticketing',
        ]);
    }

    public function state(string $contentionId): JsonResponse
    {
        return response()->json($this->contention($contentionId));
    }

    private function contention(string $contentionId): array
    {
        $contention = $this->queryBus->sendWithRouting('contention.state', $contentionId);
        abort_if($contention === null, 404, 'There is no such contention. Start one from the page of a concert on sale.');

        return $contention;
    }
}
