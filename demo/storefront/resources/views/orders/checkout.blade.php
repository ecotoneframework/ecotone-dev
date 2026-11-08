@extends('layouts.app')

@section('title', 'Checkout')

@section('body')
    <header class="page-title checkout">
        <p class="eyebrow"><x-icon name="logo"/>Checkout</p>
        <h1>{{ $concert['artist'] }}</h1>
        <p class="meta">
            <span class="meta-item"><x-icon name="building"/>{{ $concert['venueName'] }}</span>
            <span class="meta-item"><x-icon name="calendar"/>{{ \App\Web\Labels::startsAt($concert['startsAt']) }}</span>
        </p>
    </header>
    @if ($order['status'] !== 'seats_held')
        <p class="notice"><x-icon name="alert"/><span>Order <code>{{ $order['orderId'] }}</code> is {{ $order['status'] }}, so there is nothing to pay. <a href="{{ url('/orders/' . $order['orderId']) }}">Show the order</a>.</span></p>
    @else
        <div class="checkout-grid">
            <section class="card card-body" aria-labelledby="summary-title">
                <h2 id="summary-title">Your seats</h2>
                <ul class="summary-lines">
                    @foreach ($lines as $line)
                        <li class="summary-line">
                            <span><strong>{{ $line['seatCode'] }}</strong> · {{ \App\Web\Labels::tier($line['tier']) }}</span>
                            <span class="summary-line__price">{{ \App\Web\Labels::price($concert['currency'], $line['priceCents']) }}</span>
                            <span class="countdown" data-expires-at="{{ $line['expiresAt'] }}"></span>
                        </li>
                    @endforeach
                </ul>
                <p class="summary-total"><span>Total</span><span>{{ \App\Web\Labels::price($concert['currency'], $totalCents) }}</span></p>
            </section>
            <section class="card card-body" aria-labelledby="payment-title">
                <h2 id="payment-title">Payment</h2>
                @if (session('refusal'))
                    <p class="notice notice--failed">{{ session('refusal') }}</p>
                @endif
                <form method="post" action="{{ url('/orders/' . $order['orderId'] . '/checkout') }}" class="card-form">
                    @csrf
                    <div class="paying-as">
                        <span class="avatar" aria-hidden="true">{{ \App\Web\Labels::initials($customer['name']) }}</span>
                        <span>Paying as <strong>{{ $customer['name'] }}</strong><br><span class="muted">{{ $customer['email'] }}</span></span>
                    </div>
                    <div class="demo-controls" role="group" aria-labelledby="demo-controls-title">
                        <div class="demo-controls__head">
                            <p class="eyebrow" id="demo-controls-title"><x-icon name="flask"/>Demo controls</p>
                            <p>This shop has no real payment provider or mail server: choose how the stubs answer.</p>
                        </div>
                        <fieldset class="payment-outcomes">
                            <legend>How should the demo payment provider answer?</legend>
                            @foreach (\App\Payment\PaymentOutcome::cases() as $outcome)
                                <label class="choice"><input type="radio" name="paymentOutcome" value="{{ $outcome->value }}"{{ $outcome === \App\Payment\PaymentOutcome::SUCCEEDS ? ' checked' : '' }}> {{ $outcome->label() }}</label>
                            @endforeach
                        </fieldset>
                        <label class="choice email-bounce"><input type="checkbox" name="confirmationEmailBounces" value="1"> The confirmation email bounces: it lands in the dead letters, and the entry codes stay on the order page</label>
                        <p class="real-form-note">On a provider outage the charge lands in the dead letters until you replay it from Ecotone Pulse or with <code>php artisan ecotone:deadletter:replay &lt;messageId&gt;</code> in the storefront, and the replayed charge succeeds.</p>
                    </div>
                    <button type="submit" class="button button--block">Pay {{ \App\Web\Labels::price($concert['currency'], $totalCents) }}</button>
                </form>
            </section>
        </div>
    @endif
    <script src="{{ asset('js/countdown.js') }}"></script>
@endsection
