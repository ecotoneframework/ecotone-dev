@extends('layouts.app')

@section('title', $concert['artist'])

@section('body')
    <main>
        <h1>{{ $concert['artist'] }}</h1>
        <p>
            {{ $concert['venueName'] }} · {{ \Carbon\CarbonImmutable::parse($concert['startsAt'])->format('j M Y, H:i') }}
            · <span class="status">{{ $concert['status'] }}</span>
        </p>
        @foreach ($concert['sections'] as $section)
            <section class="seat-section seat-section--{{ $section['tier'] }}">
                <h2>
                    {{ $section['tier'] === 'vip' ? 'VIP' : 'Standard' }}
                    <span class="muted">{{ $concert['currency'] }} {{ number_format(($section['tier'] === 'vip' ? $concert['vipPriceCents'] : $concert['standardPriceCents']) / 100, 2) }}</span>
                </h2>
                @foreach ($section['rows'] as $row)
                    <div class="seat-row">
                        <span class="seat-row__label">Row {{ $row['row'] }}</span>
                        @foreach ($row['seats'] as $seat)
                            <span class="seat seat--{{ $seat['tier'] }}" title="{{ $seat['seatId'] }}">{{ $seat['seatCode'] }}</span>
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endforeach
    </main>
@endsection
