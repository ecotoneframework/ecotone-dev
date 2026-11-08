@extends('layouts.app')

@section('title', 'Order ' . $order['orderId'])

@section('head')
    @if (in_array($order['status'], ['paying', 'paid'], true))
        <meta http-equiv="refresh" content="2">
    @endif
@endsection

@section('body')
    @php($steps = ['seats_held' => 'Seats held', 'paying' => 'Paying', 'paid' => 'Paid', 'tickets_issued' => 'Tickets issued'])
    @php($reached = array_search($order['status'], array_keys($steps), true))
    <header class="page-title order">
        <p class="eyebrow"><x-icon name="logo"/>Your order</p>
        <h1>{{ $concert['artist'] ?? $order['concertId'] }}</h1>
        <p class="meta">
            @if ($concert !== null)
                <span class="meta-item"><x-icon name="building"/>{{ $concert['venueName'] }}</span>
                <span class="meta-item"><x-icon name="calendar"/>{{ \App\Web\Labels::startsAt($concert['startsAt']) }}</span>
            @endif
            <span class="meta-item">Order <code>{{ $order['orderId'] }}</code></span>
            <span class="badge badge--{{ $order['status'] }}"><span class="status status--{{ $order['status'] }}">{{ \App\Web\Labels::orderStatus($order['status']) }}</span></span>
        </p>
    </header>

    @if ($order['status'] !== 'failed')
        <ol class="steps" aria-label="Order progress">
            @foreach ($steps as $step => $stepLabel)
                <li class="step{{ $reached !== false && $loop->index <= $reached ? ' step--done' : '' }}{{ $reached === $loop->index ? ' step--current' : '' }}"{{ $reached === $loop->index ? ' aria-current=step' : '' }}>{{ $stepLabel }}</li>
            @endforeach
        </ol>
    @endif

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
        <section aria-labelledby="tickets-title">
            <div class="section-head"><h2 id="tickets-title">Tickets</h2></div>
            <ul class="tickets">
                @foreach ($order['tickets'] as $ticket)
                    <li class="ticket-stub ticket-stub--{{ $ticket['tier'] }}">
                        <div class="ticket-stub__body">
                            <p class="eyebrow"><x-icon name="music"/>Admit one</p>
                            <p class="ticket-stub__artist">{{ $concert['artist'] ?? $order['concertId'] }}</p>
                            <dl class="ticket-facts">
                                @if ($concert !== null)
                                    <div><dt>Venue</dt><dd>{{ $concert['venueName'] }}</dd></div>
                                    <div><dt>Date</dt><dd>{{ \App\Web\Labels::startsAt($concert['startsAt']) }}</dd></div>
                                @endif
                                <div><dt>Seat</dt><dd>{{ \App\Concert\SeatCode::ofSeatId($ticket['seatId']) }}</dd></div>
                                <div><dt>Tier</dt><dd>{{ \App\Web\Labels::tier($ticket['tier']) }}</dd></div>
                                <div><dt>Ticket</dt><dd><code>{{ $ticket['ticketNumber'] }}</code></dd></div>
                            </dl>
                        </div>
                        <div class="ticket-stub__stub">
                            <span class="eyebrow">Entry code</span>
                            <code class="entry-code">{{ $ticket['entryCode'] }}</code>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif ($order['heldSeats'] !== [])
        <section aria-labelledby="seats-title">
            <div class="section-head"><h2 id="seats-title">Seats</h2></div>
            <ul class="held-seats">
                @foreach ($order['heldSeats'] as $heldSeat)
                    <li>
                        <strong>{{ \App\Concert\SeatCode::ofSeatId($heldSeat['seatId']) }}</strong>
                        @if (in_array($order['status'], ['started', 'seats_held'], true))
                            <span class="countdown" data-expires-at="{{ $heldSeat['expiresAt'] }}"></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($order['paymentReference'] !== null)
        <section class="card card-body" aria-labelledby="payment-title">
            <h2 id="payment-title">Payment</h2>
            <p><code>{{ $order['paymentReference'] }}</code> · {{ $paymentStatus ?? 'unknown' }}</p>
        </section>
    @endif

    @switch($order['status'])
        @case('started')
            <p class="actions"><a class="button" href="{{ url('/concerts/' . $order['concertId']) }}">Pick seats</a></p>
            @break
        @case('seats_held')
            <p class="actions"><a class="button" href="{{ url('/orders/' . $order['orderId'] . '/checkout') }}">Pay for these seats</a> <a href="{{ url('/concerts/' . $order['concertId']) }}">Change seats</a></p>
            @break
        @case('paying')
            <p class="notice"><x-icon name="clock"/><span>Charging your card. This page refreshes until the payment is through.</span></p>
            @break
        @case('paid')
            <p class="notice"><x-icon name="clock"/><span>Waiting for ticketing to issue the tickets.</span></p>
            @break
    @endswitch

    @if (($concert['status'] ?? null) === 'on_sale')
        @include('rushes.panel', ['concertId' => $order['concertId']])
    @endif
    <script src="{{ asset('js/countdown.js') }}"></script>
@endsection
