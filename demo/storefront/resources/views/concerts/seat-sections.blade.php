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
                    @if ($interactive)
                        <button type="button" class="seat seat--{{ $seat['tier'] }}" data-seat-id="{{ $seat['seatId'] }}">{{ $seat['seatCode'] }}</button>
                    @else
                        <span class="seat seat--{{ $seat['tier'] }}{{ in_array($seat['seatId'], $takenSeatIds, true) ? ' seat--taken' : '' }}" title="{{ $seat['seatId'] }}">{{ $seat['seatCode'] }}</span>
                    @endif
                @endforeach
            </div>
        @endforeach
    </section>
@endforeach
