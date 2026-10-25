@extends('layouts.app')

@section('title', 'Checkout')

@section('body')
    <main class="checkout">
        <h1>Checkout</h1>
        <p>{{ $concert['artist'] }} · {{ $concert['venueName'] }} · {{ \Carbon\CarbonImmutable::parse($concert['startsAt'])->format('j M Y, H:i') }}</p>
        @if ($order['status'] !== 'seats_held')
            <p class="notice">Order <code>{{ $order['orderId'] }}</code> is {{ $order['status'] }}, so there is nothing to pay. <a href="{{ url('/orders/' . $order['orderId']) }}">Show the order</a>.</p>
        @else
            <table class="orders">
                <thead>
                    <tr><th>Seat</th><th>Tier</th><th>Price</th><th>Held for</th></tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line['seatCode'] }}</td>
                            <td>{{ $line['tier'] === 'vip' ? 'VIP' : 'Standard' }}</td>
                            <td>{{ $concert['currency'] }} {{ number_format($line['priceCents'] / 100, 2) }}</td>
                            <td><span class="countdown" data-expires-at="{{ $line['expiresAt'] }}"></span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><th colspan="2">Total</th><th colspan="2">{{ $concert['currency'] }} {{ number_format($totalCents / 100, 2) }}</th></tr>
                </tfoot>
            </table>
            @if (session('refusal'))
                <p class="notice notice--failed">{{ session('refusal') }}</p>
            @endif
            <form method="post" action="{{ url('/orders/' . $order['orderId'] . '/checkout') }}" class="card-form">
                @csrf
                <label>Card number <input type="text" name="cardNumber" inputmode="numeric" autocomplete="off" value="4242424242424242"></label>
                <button type="submit">Pay {{ $concert['currency'] }} {{ number_format($totalCents / 100, 2) }}</button>
            </form>
            <p class="muted">Demo cards: any number pays; one ending in 0000 is declined; one ending in 9999 hits a payment provider outage, so the charge lands in the dead letters until you replay it from Ecotone Pulse.</p>
        @endif
    </main>
    <script src="{{ asset('js/countdown.js') }}"></script>
@endsection
