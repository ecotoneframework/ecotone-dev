@extends('layouts.app')

@section('title', 'Concerts')

@section('body')
    <main>
        <h1>Concerts</h1>
        @if ($concerts === [])
            <p>No concerts yet. They appear here as soon as ticketing schedules one.</p>
        @endif
        <ul class="concert-list">
            @foreach ($concerts as $concert)
                <li class="concert concert--{{ $concert['status'] }}">
                    <a href="{{ url('/concerts/' . $concert['concertId']) }}">{{ $concert['artist'] }}</a>
                    <span>{{ $concert['venueName'] }}</span>
                    <span>{{ \Carbon\CarbonImmutable::parse($concert['startsAt'])->format('j M Y, H:i') }}</span>
                    <span>from {{ $concert['currency'] }} {{ number_format(min($concert['standardPriceCents'], $concert['vipPriceCents']) / 100, 2) }}</span>
                    <span class="status">{{ $concert['status'] }}</span>
                </li>
            @endforeach
        </ul>
    </main>
@endsection
