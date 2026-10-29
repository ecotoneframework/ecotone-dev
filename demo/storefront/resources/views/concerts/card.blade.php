@php($startsAt = \Carbon\CarbonImmutable::parse($concert['startsAt']))
<li>
    <a class="concert-card concert-card--{{ $concert['status'] }}" href="{{ url('/concerts/' . $concert['concertId']) }}">
        <div class="poster" style="--poster-hue: {{ \App\Web\Labels::posterHue($concert['artist']) }}" aria-hidden="true">
            <span class="poster__initials">{{ \App\Web\Labels::initials($concert['artist']) }}</span>
            <span class="date-badge"><span class="date-badge__day">{{ $startsAt->format('j') }}</span><span class="date-badge__month">{{ $startsAt->format('M') }}</span></span>
            <span class="badge badge--{{ $concert['status'] }}">{{ \App\Web\Labels::concertStatus($concert['status']) }}</span>
        </div>
        <div class="concert-card__body">
            <span class="concert-card__artist">{{ $concert['artist'] }}</span>
            <span class="meta">
                <span class="meta-item"><x-icon name="building"/>{{ $concert['venueName'] }}</span>
                <span class="meta-item"><x-icon name="calendar"/>{{ \App\Web\Labels::startsAt($concert['startsAt']) }}</span>
            </span>
            <span class="concert-card__price">from <strong>{{ \App\Web\Labels::price($concert['currency'], min($concert['standardPriceCents'], $concert['vipPriceCents'])) }}</strong></span>
            <span class="visually-hidden">{{ \App\Web\Labels::concertStatus($concert['status']) }}</span>
        </div>
    </a>
</li>
