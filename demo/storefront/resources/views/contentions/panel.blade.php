<section class="sim-panel contention-panel" data-contention-panel data-start-contention-url="{{ url('/concerts/' . $concert['concertId'] . '/contentions') }}">
    <p class="eyebrow"><x-icon name="pulse"/>Live simulation</p>
    <h2>Contend for one seat</h2>
    <p class="muted">Send several synthetic buyers after the same seat within the same moment. Ticketing decides their requests in whatever order they arrive, and the Dynamic Consistency Boundary on the seat lets exactly one hold stand: watch one buyer turn green, and every other one turn red, refused either by the boundary while another hold was being appended or because the seat was already held.</p>
    <form class="sim-panel__form contention-panel__form" data-contention-form>
        <label class="field">Seat
            <select name="seatId">
                @foreach ($concert['sections'] as $section)
                    @foreach ($section['rows'] as $row)
                        @foreach ($row['seats'] as $seat)
                            @switch($seatStateOf[$seat['seatId']])
                                @case('sold')
                                    <option value="{{ $seat['seatId'] }}" disabled>{{ $seat['seatCode'] }} · sold</option>
                                    @break
                                @case('held')
                                    <option value="{{ $seat['seatId'] }}" disabled>{{ $seat['seatCode'] }} · held by someone</option>
                                    @break
                                @case('yours')
                                    <option value="{{ $seat['seatId'] }}" disabled>{{ $seat['seatCode'] }} · yours</option>
                                    @break
                                @default
                                    <option value="{{ $seat['seatId'] }}">{{ $seat['seatCode'] }}</option>
                            @endswitch
                        @endforeach
                    @endforeach
                @endforeach
            </select>
        </label>
        <label class="field">Buyers <input type="number" name="buyers" value="{{ \App\Rush\StartContention::DEFAULT_BUYERS }}" min="{{ \App\Rush\StartContention::MIN_BUYERS }}" max="{{ \App\Rush\StartContention::MAX_BUYERS }}"></label>
        <button type="submit" class="button"><x-icon name="play"/><span>Contend for this seat with <span data-contention-buyers-count>{{ \App\Rush\StartContention::DEFAULT_BUYERS }}</span> buyers</span></button>
    </form>
    <p class="sim-status contention-panel__status" data-contention-status role="status" hidden></p>
</section>
<script src="{{ asset('js/contention.js') }}"></script>
