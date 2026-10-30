@php($stateLabels = ['free' => 'free', 'yours' => 'yours', 'held' => 'held by someone', 'sold' => 'sold'])
<div class="venue-floor">
    <div class="stage" aria-hidden="true">Stage</div>
    @foreach ($concert['sections'] as $section)
        <div class="seat-block seat-block--{{ $section['tier'] }}" role="group" aria-label="{{ \App\Web\Labels::tier($section['tier']) }} seats, {{ \App\Web\Labels::price($concert['currency'], $section['tier'] === 'vip' ? $concert['vipPriceCents'] : $concert['standardPriceCents']) }}">
            <p class="seat-block__label"><span>{{ \App\Web\Labels::tier($section['tier']) }}</span><span>{{ \App\Web\Labels::price($concert['currency'], $section['tier'] === 'vip' ? $concert['vipPriceCents'] : $concert['standardPriceCents']) }}</span></p>
            @foreach ($section['rows'] as $row)
                @php($aisleAfter = (int) ceil(count($row['seats']) / 2))
                <div class="seat-row" role="group" aria-label="Row {{ $row['row'] }}">
                    <span class="row-label" aria-hidden="true">{{ $row['row'] }}</span>
                    @foreach ($row['seats'] as $seat)
                        @php($state = $seatStateOf[$seat['seatId']])
                        @php($label = $seat['seatCode'] . ', ' . \App\Web\Labels::tier($seat['tier']) . ', ' . $stateLabels[$state])
                        @if ($interactive)
                            <button type="button" class="seat seat--{{ $seat['tier'] }}{{ $state === 'free' ? '' : ' seat--' . $state }}" data-seat-id="{{ $seat['seatId'] }}" data-seat-code="{{ $seat['seatCode'] }}" data-tier="{{ $seat['tier'] }}" aria-label="{{ $label }}" title="{{ $label }}"{{ in_array($state, ['held', 'sold'], true) ? ' disabled' : '' }}>{{ \App\Concert\SeatCode::rowAndNumber($seat['seatCode'])[1] }}</button>
                        @else
                            <span class="seat seat--{{ $seat['tier'] }}{{ $state === 'free' ? '' : ' seat--' . $state }}" role="img" aria-label="{{ $label }}" title="{{ $label }}">{{ \App\Concert\SeatCode::rowAndNumber($seat['seatCode'])[1] }}</span>
                        @endif
                        @if ($loop->iteration === $aisleAfter && ! $loop->last)<span class="aisle" aria-hidden="true"></span>@endif
                    @endforeach
                    <span class="row-label" aria-hidden="true">{{ $row['row'] }}</span>
                </div>
            @endforeach
        </div>
    @endforeach
    <p class="venue-back" aria-hidden="true">Back of the hall</p>
</div>
<ul class="legend" aria-label="Seats by state" data-seat-legend>
    <li><span class="swatch" aria-hidden="true"></span><strong data-seat-count="free">{{ $seatCounts['free'] }}</strong> free</li>
    @if ($interactive)
        <li><span class="swatch swatch--yours" aria-hidden="true"></span><strong data-seat-count="yours">{{ $seatCounts['yours'] }}</strong> yours</li>
        <li><span class="swatch swatch--pending" aria-hidden="true"></span><strong data-seat-count="pending">0</strong> asking ticketing</li>
        <li><span class="swatch swatch--refused" aria-hidden="true"></span>refused</li>
    @endif
    <li><span class="swatch swatch--held" aria-hidden="true"></span><strong data-seat-count="held">{{ $seatCounts['held'] }}</strong> held by someone</li>
    <li><span class="swatch swatch--sold" aria-hidden="true"></span><strong data-seat-count="sold">{{ $seatCounts['sold'] }}</strong> sold</li>
    <li><span class="swatch swatch--vip" aria-hidden="true"></span>VIP seat</li>
</ul>
