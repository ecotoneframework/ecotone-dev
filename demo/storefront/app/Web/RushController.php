<?php

declare(strict_types=1);

namespace App\Web;

use App\Rush\RushSimulator;
use App\Rush\StartRush;
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\Uuid;

final class RushController
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
        $rushId = Uuid::v7()->toRfc4122();
        $refusal = RushSimulator::refusalOf($concert, $concertId, $rushId);
        if ($refusal !== null) {
            return response()->json(['error' => $refusal->getMessage()], 409);
        }
        $settings = [];
        foreach (StartRush::DEFAULTS as $setting => $default) {
            $value = (string) $request->input($setting, $default);
            $settings[$setting] = filter_var($value, FILTER_VALIDATE_INT);
            if ($settings[$setting] === false) {
                return response()->json(['error' => sprintf('The %s of a rush is "%s", not a whole number, so the rush cannot start. Give %s as a whole number, or leave it out to use %d.', $setting, $value, $setting, $default)], 422);
            }
        }
        try {
            $command = new StartRush(...['rushId' => $rushId, 'concertId' => $concertId, ...$settings]);
        } catch (DomainException $refusal) {
            return response()->json(['error' => $refusal->getMessage()], 422);
        }
        $this->commandBus->send($command);

        return response()->json(['rushId' => $rushId, 'stateUrl' => url('/rushes/' . $rushId . '/state.json')], 202);
    }

    public function state(string $rushId): JsonResponse
    {
        $state = $this->queryBus->sendWithRouting('rush.state', $rushId);
        abort_if($state === null, 404, 'There is no such rush. Start one from the page of a concert on sale.');

        return response()->json($state);
    }
}
