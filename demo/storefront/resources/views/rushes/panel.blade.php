<section class="sim-panel rush-panel" data-rush-panel data-concert-id="{{ $concertId }}" data-start-rush-url="{{ url('/concerts/' . $concertId . '/rushes') }}">
    <p class="eyebrow"><x-icon name="pulse"/>Live simulation</p>
    <h2>Simulate a rush</h2>
    <p class="muted">Send a crowd of synthetic buyers after the seats of this concert while you pick yours. Some pay with a declined card, some hit a payment outage, some have their confirmation email bounce: watch them land in the dead letters of Ecotone Pulse and in the traces of Jaeger.</p>
    <form class="sim-panel__form rush-panel__form" data-rush-form>
        <label class="field">Buyers <input type="number" name="buyers" value="{{ \App\Rush\StartRush::DEFAULTS['buyers'] }}" min="1" max="{{ \App\Rush\StartRush::MAX_BUYERS }}"></label>
        <label class="field">Declined cards, % <input type="number" name="declinedShare" value="{{ \App\Rush\StartRush::DEFAULTS['declinedShare'] }}" min="0" max="100"></label>
        <label class="field">Payment outages, % <input type="number" name="outageShare" value="{{ \App\Rush\StartRush::DEFAULTS['outageShare'] }}" min="0" max="100"></label>
        <label class="field">Bouncing emails, % <input type="number" name="bounceShare" value="{{ \App\Rush\StartRush::DEFAULTS['bounceShare'] }}" min="0" max="100"></label>
        <label class="field">Seats per buyer <input type="number" name="seatsPerBuyer" value="{{ \App\Rush\StartRush::DEFAULTS['seatsPerBuyer'] }}" min="1" max="{{ \App\Rush\StartRush::MAX_SEATS_PER_BUYER }}"></label>
        <button type="submit" class="button"><x-icon name="play"/>Start the rush</button>
    </form>
    <p class="sim-status rush-panel__status" data-rush-status role="status" hidden></p>
</section>
<script src="{{ asset('js/rush.js') }}"></script>
