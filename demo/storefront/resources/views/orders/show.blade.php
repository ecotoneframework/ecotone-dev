@extends('layouts.app')

@section('title', 'Order ' . $order['orderId'])

@section('head')
    @if (in_array($order['status'], ['paying', 'paid'], true))
        <meta http-equiv="refresh" content="2">
    @endif
@endsection

@section('body')
    <main class="order">
        <h1>{{ $concert['artist'] ?? $order['concertId'] }}</h1>
        <p class="muted">Order <code>{{ $order['orderId'] }}</code> · <span class="status status--{{ $order['status'] }}">{{ $order['status'] }}</span></p>

        @if ($order['failureReason'] !== null)
            <p class="notice notice--failed">{{ $order['failureReason'] }}</p>
        @endif

        @if ($order['lastRefusal'] !== null)
            <p class="notice notice--refused">Seat {{ \App\Concert\SeatCode::ofSeatId($order['lastRefusal']['seatId']) }} was refused: {{ $order['lastRefusal']['reason'] }}</p>
        @endif

        @if ($order['confirmationEmailBounces'])
            <p class="notice">You chose at checkout that the confirmation email bounces, so the entry codes reach you here, on the order page.</p>
        @endif

        @if ($order['tickets'] !== [])
            <h2>Tickets</h2>
            <table class="orders">
                <thead>
                    <tr><th>Ticket</th><th>Seat</th><th>Tier</th><th>Entry code</th></tr>
                </thead>
                <tbody>
                    @foreach ($order['tickets'] as $ticket)
                        <tr>
                            <td>{{ $ticket['ticketNumber'] }}</td>
                            <td>{{ \App\Concert\SeatCode::ofSeatId($ticket['seatId']) }}</td>
                            <td>{{ $ticket['tier'] === 'vip' ? 'VIP' : 'Standard' }}</td>
                            <td><code class="entry-code">{{ $ticket['entryCode'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @elseif ($order['heldSeats'] !== [])
            <h2>Seats</h2>
            <ul class="held-seats">
                @foreach ($order['heldSeats'] as $heldSeat)
                    <li>
                        {{ \App\Concert\SeatCode::ofSeatId($heldSeat['seatId']) }}
                        @if (in_array($order['status'], ['started', 'seats_held'], true))
                            <span class="countdown" data-expires-at="{{ $heldSeat['expiresAt'] }}"></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($order['paymentReference'] !== null)
            <h2>Payment</h2>
            <p><code>{{ $order['paymentReference'] }}</code> · {{ $paymentStatus ?? 'unknown' }}</p>
        @endif

        @if (($concert['status'] ?? null) === 'on_sale')
            @include('rushes.panel', ['concertId' => $order['concertId']])
        @endif

        @switch($order['status'])
            @case('started')
                <p><a href="{{ url('/concerts/' . $order['concertId']) }}">Pick seats</a></p>
                @break
            @case('seats_held')
                <p><a href="{{ url('/orders/' . $order['orderId'] . '/checkout') }}">Pay for these seats</a> · <a href="{{ url('/concerts/' . $order['concertId']) }}">Change seats</a></p>
                @break
            @case('paying')
                <p class="muted">Charging your card. This page refreshes until the payment is through.</p>
                @break
            @case('paid')
                <p class="muted">Waiting for ticketing to issue the tickets.</p>
                @break
        @endswitch
    </main>
    <script src="{{ asset('js/countdown.js') }}"></script>
@endsection
