<?php

declare(strict_types=1);

namespace App\Web;

use App\Concert\SeatCode;
use App\Ordering\ReleaseSeat;
use App\Ordering\RequestSeat;
use App\Ordering\StartOrder;
use App\Payment\Pay;
use DomainException;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\Uid\Uuid;

final class OrderController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly CurrentCustomer $currentCustomer,
    ) {
    }

    public function start(Request $request, string $concertId): JsonResponse
    {
        $customer = $this->currentCustomer->of($request);
        if ($customer === null) {
            return response()->json(['error' => 'No customer is chosen, so there is no one to start the order for. Pick who you are on /whoami, then pick the seats again.'], 403);
        }
        $concert = $this->queryBus->sendWithRouting('concert.detail', $concertId);
        abort_if($concert === null, 404, 'There is no such concert. Pick one from /concerts.');
        if ($concert['status'] !== 'on_sale') {
            return response()->json(['error' => sprintf('Concert %s is %s, not on sale, so its seats cannot be picked %s', $concertId, $concert['status'], match ($concert['status']) {
                'scheduled' => 'yet. Wait until ticketing opens its sales, then pick the seats again.',
                'sold_out' => 'any more: every seat is sold. Pick another concert from /concerts.',
                default => 'any more. Pick another concert from /concerts.',
            })], 409);
        }

        $orderId = $this->queryBus->sendWithRouting('customer.open_order', ['customerId' => $customer['customerId'], 'concertId' => $concertId])
            ?? $this->commandBus->send(new StartOrder(customerId: $customer['customerId'], concertId: $concertId));

        return response()->json(['orderId' => $orderId]);
    }

    public function requestSeat(Request $request, string $orderId): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderId);
        $seatId = (string) $request->input('seatId');

        return $this->refusedAsConflict(function () use ($order, $seatId) {
            $this->commandBus->send(new RequestSeat(orderId: $order['orderId'], seatId: $seatId, holdId: Uuid::v7()->toRfc4122()));

            return response()->json(['requested' => $seatId], 202);
        });
    }

    public function releaseSeat(Request $request, string $orderId): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderId);
        $seatId = (string) $request->input('seatId');

        return $this->refusedAsConflict(function () use ($order, $seatId) {
            $this->commandBus->send(new ReleaseSeat(orderId: $order['orderId'], seatId: $seatId));

            return response()->json(['released' => $seatId]);
        });
    }

    public function state(Request $request, string $orderId): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderId);
        $heldSeats = array_map(fn (array $heldSeat) => [
            'seatId' => $heldSeat['seatId'],
            'seatCode' => SeatCode::ofSeatId($heldSeat['seatId']),
            'expiresAt' => $heldSeat['expiresAt'],
        ], $order['heldSeats']);

        return response()->json([
            'orderId' => $order['orderId'],
            'concertId' => $order['concertId'],
            'status' => $order['status'],
            'heldSeats' => $heldSeats,
            'earliestExpiresAt' => $heldSeats === [] ? null : min(array_column($heldSeats, 'expiresAt')),
            'lastRefusal' => $order['lastRefusal'],
            'takenSeatIds' => $this->queryBus->sendWithRouting('concert.taken_seats', ['concertId' => $order['concertId'], 'excludingOrderId' => $order['orderId']]),
            'failureReason' => $order['failureReason'],
            'tickets' => $order['tickets'],
        ]);
    }

    public function show(Request $request, string $orderId): View
    {
        $order = $this->ownedOrder($request, $orderId);

        return view('orders.show', [
            'order' => $order,
            'concert' => $this->queryBus->sendWithRouting('concert.detail', $order['concertId']),
            'paymentStatus' => $order['paymentReference'] === null ? null : $this->queryBus->sendWithRouting('payment.status', $order['paymentReference']),
        ]);
    }

    public function checkout(Request $request, string $orderId): View
    {
        $order = $this->ownedOrder($request, $orderId);
        $concert = $this->queryBus->sendWithRouting('concert.detail', $order['concertId']);
        $tiers = [];
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['seats'] as $seat) {
                    $tiers[$seat['seatId']] = $seat['tier'];
                }
            }
        }
        $lines = array_map(fn (array $heldSeat) => [
            'seatCode' => SeatCode::ofSeatId($heldSeat['seatId']),
            'tier' => $tiers[$heldSeat['seatId']],
            'priceCents' => $tiers[$heldSeat['seatId']] === 'vip' ? $concert['vipPriceCents'] : $concert['standardPriceCents'],
            'expiresAt' => $heldSeat['expiresAt'],
        ], $order['heldSeats']);

        return view('orders.checkout', [
            'order' => $order,
            'concert' => $concert,
            'lines' => $lines,
            'totalCents' => array_sum(array_column($lines, 'priceCents')),
        ]);
    }

    public function pay(Request $request, string $orderId): RedirectResponse
    {
        $order = $this->ownedOrder($request, $orderId);
        try {
            $this->commandBus->send(new Pay(orderId: $order['orderId'], cardNumber: (string) $request->input('cardNumber')));
        } catch (DomainException $refusal) {
            return back()->with('refusal', $refusal->getMessage());
        }

        return redirect('/orders/' . $order['orderId']);
    }

    private function ownedOrder(Request $request, string $orderId): array
    {
        $customer = $this->currentCustomer->of($request);
        abort_if($customer === null || ! Str::isUuid($orderId), 404, 'There is no such order of yours. Your orders are listed on /account.');
        try {
            $order = $this->queryBus->sendWithRouting('order.detail', $orderId);
        } catch (DomainException) {
            abort(404, 'There is no such order of yours. Your orders are listed on /account.');
        }
        abort_if($order['customerId'] !== $customer['customerId'], 404, 'There is no such order of yours. Your orders are listed on /account.');

        return $order;
    }

    private function refusedAsConflict(callable $send): JsonResponse
    {
        try {
            return $send();
        } catch (DomainException $refusal) {
            return response()->json(['error' => $refusal->getMessage()], 409);
        }
    }
}
