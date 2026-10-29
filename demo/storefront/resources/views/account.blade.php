@extends('layouts.app')

@section('title', 'My tickets')

@section('body')
    <header class="page-title">
        <p class="eyebrow"><x-icon name="logo"/>Account</p>
        <h1>My tickets</h1>
    </header>
    @if ($orders === [])
        <div class="empty-state">
            <p>No orders yet. Pick a concert from <a href="{{ url('/concerts') }}">the list</a>.</p>
        </div>
    @else
        <ul class="order-cards orders">
            @foreach ($orders as $order)
                <li class="card">
                    <div class="order-card">
                        <div class="order-card__head">
                            <strong>{{ $order['artist'] }}</strong>
                            <span class="badge badge--{{ $order['status'] }}"><span class="status">{{ \App\Web\Labels::orderStatus($order['status']) }}</span></span>
                        </div>
                        <span class="meta">
                            @if ($order['startsAt'] !== null)
                                <span class="meta-item"><x-icon name="calendar"/>{{ \App\Web\Labels::startsAt($order['startsAt']) }}</span>
                            @endif
                            <span class="meta-item"><x-icon name="seats"/>{{ $order['seatIds'] === [] ? 'No seats' : implode(', ', array_map(\App\Concert\SeatCode::ofSeatId(...), $order['seatIds'])) }}</span>
                        </span>
                        @if ($order['total'] !== null)
                            <p class="order-card__total">Total <strong>{{ $order['total'] }}</strong></p>
                        @endif
                        <div class="order-card__foot">
                            <a class="button button--secondary" href="{{ url('/orders/' . $order['orderId']) }}">View tickets <x-icon name="arrow"/></a>
                            <span class="muted">Order <code>{{ $order['orderId'] }}</code></span>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
