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
                <fieldset class="payment-outcomes">
                    <legend>How should the demo payment provider answer?</legend>
                    @foreach (\App\Payment\PaymentOutcome::cases() as $outcome)
                        <label><input type="radio" name="paymentOutcome" value="{{ $outcome->value }}"{{ $outcome === \App\Payment\PaymentOutcome::SUCCEEDS ? ' checked' : '' }}> {{ $outcome->label() }}</label>
                    @endforeach
                </fieldset>
                <label class="email-bounce"><input type="checkbox" name="confirmationEmailBounces" value="1"> The confirmation email bounces: it lands in the dead letters, and the entry codes stay on the order page</label>
                <button type="submit">Pay {{ $concert['currency'] }} {{ number_format($totalCents / 100, 2) }}</button>
            </form>
            <p class="muted">The storefront charges through a payment stub, so you choose its answer instead of typing a card. On a provider outage the charge lands in the dead letters until you replay it from Ecotone Pulse or with <code>php artisan ecotone:deadletter:replay &lt;messageId&gt;</code> in the storefront, and the replayed charge succeeds.</p>
        @endif
    </main>
    <script src="{{ asset('js/countdown.js') }}"></script>
@endsection
