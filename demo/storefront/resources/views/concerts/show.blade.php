@extends('layouts.app')

@section('title', $concert['artist'])

@section('body')
    <main>
        <h1>{{ $concert['artist'] }}</h1>
        <p>
            {{ $concert['venueName'] }} · {{ \Carbon\CarbonImmutable::parse($concert['startsAt'])->format('j M Y, H:i') }}
            · <span class="status">{{ $concert['status'] }}</span>
        </p>
        @if ($concert['status'] === 'sold_out')
            <p class="notice notice--sold-out">Sold out</p>
        @elseif ($concert['status'] === 'on_sale' && $customer === null)
            <p class="notice"><a href="{{ url('/whoami') }}">Pick who you are</a> to choose seats.</p>
        @endif
        @if ($concert['status'] === 'on_sale')
            @include('rushes.panel', ['concertId' => $concert['concertId']])
            @include('contentions.panel', ['concert' => $concert])
        @endif
        @if ($picksSeats)
            <div class="seat-picker-layout">
                <div class="seat-picker" data-seat-picker data-order-id="{{ $openOrderId }}" data-start-order-url="{{ url('/concerts/' . $concert['concertId'] . '/orders') }}" data-orders-url="{{ url('/orders') }}" data-taken-seats-url="{{ url('/concerts/' . $concert['concertId'] . '/taken-seats.json') }}" data-taken-seat-ids="{{ json_encode($takenSeatIds) }}">
                    <p class="muted">Click a free seat to hold it, click one of yours to let it go. A customer holds at most 4 seats per concert.</p>
                    @include('concerts.seat-sections', ['interactive' => true])
                    <p class="legend">
                        <span class="seat">free</span> <span class="seat seat--pending">asking ticketing</span> <span class="seat seat--yours">yours</span> <span class="seat seat--taken">held by someone</span> <span class="seat seat--refused">refused</span>
                    </p>
                </div>
                <aside class="basket" data-basket>
                    <h2>Your basket</h2>
                    <p class="basket__refusal" data-basket-refusal hidden></p>
                    <p class="basket__empty" data-basket-empty>No seats held yet.</p>
                    <ul class="basket__seats" data-basket-seats></ul>
                    <p class="basket__expiry" data-basket-expiry hidden>Your seats are held for <span class="countdown" data-basket-countdown></span></p>
                    <p><a class="button" data-basket-checkout hidden href="#">Go to checkout</a></p>
                </aside>
            </div>
            <script src="{{ asset('js/countdown.js') }}"></script>
            <script src="{{ asset('js/seat-picker.js') }}"></script>
        @else
            @include('concerts.seat-sections', ['interactive' => false])
        @endif
    </main>
@endsection
