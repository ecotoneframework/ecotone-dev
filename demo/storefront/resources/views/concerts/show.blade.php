@extends('layouts.app')

@section('title', $concert['artist'])

@section('hero')
    @php($startsAt = \Carbon\CarbonImmutable::parse($concert['startsAt']))
    <section class="event-header" aria-labelledby="concert-title">
        <div class="event-header__inner">
            <div class="poster" style="--poster-hue: {{ \App\Web\Labels::posterHue($concert['artist']) }}" aria-hidden="true">
                <span class="poster__initials">{{ \App\Web\Labels::initials($concert['artist']) }}</span>
            </div>
            <div class="event-header__text">
                <p class="eyebrow"><x-icon name="music"/>Live in concert</p>
                <h1 id="concert-title">{{ $concert['artist'] }}</h1>
                <p class="meta">
                    <span class="badge badge--{{ $concert['status'] }}"><span class="status">{{ \App\Web\Labels::concertStatus($concert['status']) }}</span></span>
                    <span class="meta-item"><x-icon name="building"/>{{ $concert['venueName'] }}</span>
                    <span class="meta-item"><x-icon name="calendar"/>{{ $startsAt->format('D, j M Y · H:i') }}</span>
                </p>
                <ul class="price-chips" aria-label="Prices">
                    <li class="price-chip price-chip--vip">VIP <strong>{{ \App\Web\Labels::price($concert['currency'], $concert['vipPriceCents']) }}</strong></li>
                    <li class="price-chip">Standard <strong>{{ \App\Web\Labels::price($concert['currency'], $concert['standardPriceCents']) }}</strong></li>
                </ul>
            </div>
        </div>
    </section>
@endsection

@section('body')
    @if ($concert['status'] === 'sold_out')
        <p class="notice notice--sold-out">Sold out</p>
    @elseif ($concert['status'] === 'on_sale' && $customer === null)
        <p class="notice"><x-icon name="user"/><span><a href="{{ url('/whoami') }}">Pick who you are</a> to choose seats.</span></p>
    @endif
    @if ($picksSeats)
        <div class="seat-picker-layout">
            <section class="card hall seat-picker" aria-labelledby="hall-title" data-seat-picker data-order-id="{{ $openOrderId }}" data-start-order-url="{{ url('/concerts/' . $concert['concertId'] . '/orders') }}" data-orders-url="{{ url('/orders') }}" data-taken-seats-url="{{ url('/concerts/' . $concert['concertId'] . '/taken-seats.json') }}" data-taken-seat-ids="{{ json_encode($takenSeatIds) }}" data-sold-seat-ids="{{ json_encode($soldSeatIds) }}" data-currency="{{ $concert['currency'] }}" data-vip-price-cents="{{ $concert['vipPriceCents'] }}" data-standard-price-cents="{{ $concert['standardPriceCents'] }}">
                <div class="hall__head">
                    <h2 id="hall-title">Pick your seats</h2>
                    <p class="muted">Click a free seat to hold it, click one of yours to let it go. A customer holds at most 4 seats per concert.</p>
                </div>
                @include('concerts.seat-sections', ['interactive' => true])
            </section>
            <aside class="card basket" data-basket aria-labelledby="basket-title">
                <h2 id="basket-title">Your order</h2>
                <p class="basket__refusal" data-basket-refusal role="status" hidden></p>
                <p class="basket__empty muted" data-basket-empty>No seats held yet. Pick a free seat on the hall's map.</p>
                <ul class="basket__seats" data-basket-seats></ul>
                <p class="basket__expiry" data-basket-expiry hidden><x-icon name="clock"/><span>Your seats are held for <span class="countdown" data-basket-countdown></span></span></p>
                <p class="basket__total" data-basket-total hidden><span>Total</span><span data-basket-total-amount></span></p>
                <p><a class="button button--block" data-basket-checkout hidden href="#">Continue to checkout <x-icon name="arrow"/></a></p>
            </aside>
        </div>
        <script src="{{ asset('js/countdown.js') }}"></script>
        <script src="{{ asset('js/seat-picker.js') }}"></script>
    @else
        <section class="card hall" aria-labelledby="hall-title">
            <div class="hall__head">
                <h2 id="hall-title">The hall</h2>
            </div>
            @include('concerts.seat-sections', ['interactive' => false])
        </section>
    @endif
    @if ($concert['status'] === 'on_sale')
        <section aria-labelledby="simulations-title">
            <div class="section-head">
                <div class="page-title">
                    <p class="eyebrow"><x-icon name="pulse"/>Demo</p>
                    <h2 id="simulations-title">Live simulation</h2>
                </div>
                <p class="muted">Send synthetic buyers after these seats and watch Ecotone decide.</p>
            </div>
            <div class="simulations">
                @include('rushes.panel', ['concertId' => $concert['concertId']])
                @include('contentions.panel', ['concert' => $concert, 'seatStateOf' => $seatStateOf])
            </div>
        </section>
    @endif
@endsection
