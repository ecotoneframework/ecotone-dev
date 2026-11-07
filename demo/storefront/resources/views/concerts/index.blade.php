@extends('layouts.app')

@section('title', 'Concerts')

@section('body')
    <header class="page-title">
        <p class="eyebrow"><x-icon name="music"/>Live in Riverside Hall</p>
        <h1>Concerts</h1>
    </header>
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
@endsection
