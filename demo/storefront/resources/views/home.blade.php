@extends('layouts.app')

@section('title', 'Tickets for live music')

@section('hero')
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero__inner">
            <p class="eyebrow"><x-icon name="music"/>Live in Riverside Hall</p>
            <h1 id="hero-title">Your seat for the night is waiting</h1>
            <p>Pick a concert, choose your seats on the hall's map and hold them while you pay. Your tickets and entry codes arrive the moment ticketing issues them.</p>
            <div class="hero__actions">
                <a class="button" href="{{ url('/concerts') }}">Browse concerts <x-icon name="arrow"/></a>
                <a class="button button--secondary" href="{{ url('/whoami') }}">Pick who you are</a>
            </div>
        </div>
    </section>
@endsection

@section('body')
    <section aria-labelledby="concerts-title">
        <div class="section-head">
            <h2 id="concerts-title">Concerts</h2>
            <a href="{{ url('/concerts') }}">See all concerts</a>
        </div>
        @if ($concerts === [])
            <div class="empty-state">
                <p>No concerts yet. They appear here as soon as ticketing schedules one.</p>
            </div>
        @else
            <ul class="concert-grid">
                @foreach ($concerts as $concert)
                    @include('concerts.card', ['concert' => $concert])
                @endforeach
            </ul>
        @endif
    </section>
    <section class="behind-the-scenes" aria-labelledby="behind-title">
        <h2 id="behind-title">Behind the scenes</h2>
        <p>Stagefront is the Laravel storefront of the Ecotone demo. It talks to the ticketing service over the Distributed Bus on RabbitMQ.</p>
        <ul>
            <li><x-icon name="pulse"/><span><a href="{{ $pulseUrl }}">Ecotone Pulse</a> monitors this service and ticketing.</span></li>
            <li><x-icon name="trace"/><span><a href="{{ $jaegerUrl }}">Jaeger</a> shows the traces of every message.</span></li>
        </ul>
    </section>
@endsection
