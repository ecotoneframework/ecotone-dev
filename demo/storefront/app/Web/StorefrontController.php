<?php

declare(strict_types=1);

namespace App\Web;

use Ecotone\Api\Gateway\QueryBus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class StorefrontController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly CurrentCustomer $currentCustomer,
    ) {
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

    public function concert(string $concertId): View
    {
        $concert = $this->queryBus->sendWithRouting('concert.detail', $concertId);
        abort_if($concert === null, 404, 'There is no such concert. Pick one from /concerts.');

        return view('concerts.show', ['concert' => $concert]);
    }

    public function account(Request $request): View|RedirectResponse
    {
        $customer = $this->currentCustomer->of($request);
        if ($customer === null) {
            return redirect('/whoami');
        }

        $concerts = array_column($this->queryBus->sendWithRouting('concerts.list'), null, 'concertId');

        return view('account', [
            'orders' => array_map(
                fn (array $order) => [...$order, 'artist' => $concerts[$order['concertId']]['artist'] ?? $order['concertId']],
                $this->queryBus->sendWithRouting('customer.orders', $customer['customerId']),
            ),
        ]);
    }
}
