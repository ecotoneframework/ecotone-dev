<section class="rush-panel" data-rush-panel data-concert-id="{{ $concertId }}" data-start-rush-url="{{ url('/concerts/' . $concertId . '/rushes') }}">
    <h2>Simulate a rush</h2>
    <p class="muted">Send a crowd of synthetic buyers after the seats of this concert while you pick yours. Some pay with a declined card, some hit a payment outage, some have an address that bounces: watch them land in the dead letters of Ecotone Pulse and in the traces of Jaeger.</p>
    <form class="rush-panel__form" data-rush-form>
        <label>Buyers <input type="number" name="buyers" value="{{ \App\Web\RushController::DEFAULTS['buyers'] }}" min="1" max="{{ \App\Rush\StartRush::MAX_BUYERS }}"></label>
        <label>Declined cards, % <input type="number" name="declinedShare" value="{{ \App\Web\RushController::DEFAULTS['declinedShare'] }}" min="0" max="100"></label>
        <label>Payment outages, % <input type="number" name="outageShare" value="{{ \App\Web\RushController::DEFAULTS['outageShare'] }}" min="0" max="100"></label>
        <label>Bouncing emails, % <input type="number" name="bounceShare" value="{{ \App\Web\RushController::DEFAULTS['bounceShare'] }}" min="0" max="100"></label>
        <label>Seats per buyer <input type="number" name="seatsPerBuyer" value="{{ \App\Web\RushController::DEFAULTS['seatsPerBuyer'] }}" min="1" max="{{ \App\Rush\StartRush::MAX_SEATS_PER_BUYER }}"></label>
        <button type="submit" class="button">Start the rush</button>
    </form>
    <p class="rush-panel__status" data-rush-status hidden></p>
</section>
<script src="{{ asset('js/rush.js') }}"></script>
