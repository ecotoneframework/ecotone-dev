<?php

declare(strict_types=1);

namespace App\Web;

use App\Concert\SeatStateProjector;
use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

final class StorefrontController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly CurrentCustomer $currentCustomer,
    ) {
    }

    public function home(): View
    {
        return view('home', [
            'concerts' => $this->queryBus->sendWithRouting('concerts.list'),
            'pulseUrl' => config('demo.pulse_url'),
            'jaegerUrl' => config('demo.jaeger_url') . '/search?service=storefront',
        ]);
    }

    public function whoami(): View
    {
        return view('whoami', ['customers' => $this->queryBus->sendWithRouting('customers.list')]);
    }

    public function chooseCustomer(Request $request): RedirectResponse
    {
        $customer = $this->currentCustomer->find((string) $request->input('customerId'));
        abort_if($customer === null, 404, 'There is no such customer. Pick one of the customers listed on /whoami.');

        return redirect('/concerts')->withCookie(Cookie::forever(CurrentCustomer::COOKIE, $customer['customerId']));
    }

    public function concerts(): View
    {
        return view('concerts.index', ['concerts' => $this->queryBus->sendWithRouting('concerts.list')]);
    }

    public function concert(Request $request, string $concertId): View
    {
        $concert = $this->queryBus->sendWithRouting('concert.detail', $concertId);
        abort_if($concert === null, 404, 'There is no such concert. Pick one from /concerts.');
        $customer = $this->currentCustomer->of($request);
        $openOrderId = $customer === null ? null : $this->queryBus->sendWithRouting('customer.open_order', ['customerId' => $customer['customerId'], 'concertId' => $concertId]);
        $seatStates = $this->queryBus->sendWithRouting('concert.seat_states', ['concertId' => $concertId, 'excludingOrderId' => $openOrderId]);
        $yourSeatIds = $openOrderId === null ? [] : array_column(array_filter($this->queryBus->sendWithRouting('order.detail', $openOrderId)['heldSeats'], fn (array $heldSeat) => Carbon::parse($heldSeat['expiresAt'])->isAfter(Carbon::now())), 'seatId');
        $seatStateOf = [];
        foreach ($concert['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['seats'] as $seat) {
                    $seatStateOf[$seat['seatId']] = match (true) {
                        in_array($seat['seatId'], $seatStates['soldSeatIds'], true) => 'sold',
                        in_array($seat['seatId'], $yourSeatIds, true) => 'yours',
                        in_array($seat['seatId'], $seatStates['heldSeatIds'], true) => 'held',
                        default => 'free',
                    };
                }
            }
        }

        return view('concerts.show', [
            'concert' => $concert,
            'customer' => $customer,
            'picksSeats' => $customer !== null && $concert['status'] === 'on_sale',
            'openOrderId' => $openOrderId,
            'takenSeatIds' => SeatStateProjector::takenOf($seatStates),
            'soldSeatIds' => $seatStates['soldSeatIds'],
            'seatStateOf' => $seatStateOf,
            'seatCounts' => array_merge(['free' => 0, 'yours' => 0, 'held' => 0, 'sold' => 0], array_count_values($seatStateOf)),
        ]);
    }

    public function takenSeats(string $concertId): JsonResponse
    {
        abort_if($this->queryBus->sendWithRouting('concert.detail', $concertId) === null, 404, 'There is no such concert. Pick one from /concerts.');

        $seatStates = $this->queryBus->sendWithRouting('concert.seat_states', ['concertId' => $concertId, 'excludingOrderId' => null]);

        return response()->json([
            'takenSeatIds' => SeatStateProjector::takenOf($seatStates),
            'soldSeatIds' => $seatStates['soldSeatIds'],
        ]);
    }

    public function account(Request $request): View|RedirectResponse
    {
        $customer = $this->currentCustomer->of($request);
        if ($customer === null) {
            return redirect('/whoami');
        }

        $concerts = [];
        $orders = [];
        foreach ($this->queryBus->sendWithRouting('customer.orders', $customer['customerId']) as $order) {
            $concert = $concerts[$order['concertId']] ??= $this->queryBus->sendWithRouting('concert.detail', $order['concertId']);
            $tiers = $concert === null ? [] : SeatPrices::tiersOf($concert);
            $orders[] = [
                ...$order,
                'artist' => $concert['artist'] ?? $order['concertId'],
                'startsAt' => $concert['startsAt'] ?? null,
                'total' => $concert === null ? null : Labels::price($concert['currency'], array_sum(array_map(fn (string $seatId) => SeatPrices::priceCentsOf($concert, $tiers[$seatId]), $order['seatIds']))),
            ];
        }

        return view('account', ['orders' => $orders]);
    }
}
