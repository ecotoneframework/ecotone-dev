@extends('layouts.app')

@section('title', 'Contention for seat ' . $contention['seatCode'])

@section('body')
    <main class="contention" data-contention data-state-url="{{ url('/contentions/' . $contention['contentionId'] . '/state.json') }}">
        <h1 data-contention-headline>{{ $contention['outcome']['headline'] }}</h1>
        <p class="contention__outcome-reason" data-contention-outcome-reason{{ $contention['outcome']['reason'] === null ? ' hidden' : '' }}>@if ($contention['outcome']['reason'] !== null)Ticketing said: {{ $contention['outcome']['reason'] }}@endif</p>
        <p>
            {{ $contention['buyersRequested'] }} synthetic buyers asked ticketing for seat <strong>{{ $contention['seatCode'] }}</strong>@if ($concert !== null) of <a href="{{ url('/concerts/' . $concert['concertId']) }}">{{ $concert['artist'] }}</a>@endif within the same moment. Ticketing decides them in whatever order they arrive, and the Dynamic Consistency Boundary on the seat lets exactly one hold stand: a request that reaches the seat while another is being appended is refused by the boundary itself, a later one is refused because the seat is already held.
        </p>
        <ol class="contention__buyers" data-contention-buyers>
            @foreach ($contention['buyers'] as $buyer)
                <li class="contention__buyer contention__buyer--{{ $buyer['state'] }}">
                    <span class="contention__name">{{ $buyer['name'] }}</span>
                    <span class="contention__state">{{ $buyer['state'] }}</span>
                    @if ($buyer['refusal'] !== null)
                        <span class="contention__refusal contention__refusal--{{ $buyer['refusal'] }}">{{ $buyer['refusalExplanation'] }}</span>
                    @endif
                    @if ($buyer['reason'] !== null)
                        <span class="contention__reason">{{ $buyer['reason'] }}</span>
                    @endif
                    @if ($buyer['state'] === 'held')
                        <form method="post" action="{{ url('/whoami') }}" class="contention__continue">
                            @csrf
                            <input type="hidden" name="customerId" value="{{ $buyer['customerId'] }}">
                            <button type="submit" class="button">Continue as {{ $buyer['name'] }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
        <p class="muted">The held buyer's order is a normal held order: continue as that buyer to pay for it, or let the hold expire when its countdown runs out. Each refused buyer's order fails with ticketing's reason. In <a href="{{ $jaegerUrl }}">Jaeger</a> the contention is one trace across both services, with one <code>SeatSalesOverDistributedBus::hold</code> per buyer: a request that read the seat already held is refused in its <code>Decision Models: App\Sales\SeatSales::hold</code>, and one that raced the winner to the append fails its <code>Conditional Append</code>, marked as an error, and is refused because the seat changed.</p>
    </main>
    <script src="{{ asset('js/contention.js') }}"></script>
@endsection
