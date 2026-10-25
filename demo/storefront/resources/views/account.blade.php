@extends('layouts.app')

@section('title', 'Your orders')

@section('body')
    <main>
        <h1>Your orders</h1>
        @if ($orders === [])
            <p>No orders yet. Pick a concert from <a href="{{ url('/concerts') }}">the list</a>.</p>
        @else
            <table class="orders">
                <thead>
                    <tr><th>Order</th><th>Concert</th><th>Seats</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><code>{{ $order['orderId'] }}</code></td>
                            <td>{{ $order['artist'] }}</td>
                            <td>{{ implode(', ', array_map(fn (string $seatId) => substr($seatId, strrpos($seatId, '/') + 1), $order['seatIds'])) }}</td>
                            <td class="status">{{ $order['status'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
@endsection
