@extends('layouts.app')

@section('title', 'Storefront')

@section('body')
    <main>
        <h1>Storefront</h1>
        <p>The Laravel storefront of the Ecotone demo. It talks to the ticketing service over the Distributed Bus on RabbitMQ.</p>
        <ul>
            <li><a href="{{ $pulseUrl }}">Ecotone Pulse</a> monitors this service and ticketing.</li>
            <li><a href="{{ $jaegerUrl }}">Jaeger</a> shows the traces of every message.</li>
        </ul>
    </main>
@endsection
