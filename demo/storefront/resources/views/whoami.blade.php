@extends('layouts.app')

@section('title', 'Who are you?')

@section('body')
    <main>
        <h1>Who are you?</h1>
        <p>The demo has no login: pick a customer and the storefront remembers your choice in a cookie.</p>
        @if ($customers === [])
            <p>No customers yet. Run <code>php artisan demo:seed</code> in the storefront container to register the demo customers.</p>
        @endif
        <ul class="customer-list">
            @foreach ($customers as $customer)
                <li>
                    <form method="post" action="{{ url('/whoami') }}">
                        @csrf
                        <input type="hidden" name="customerId" value="{{ $customer['customerId'] }}">
                        <button type="submit">{{ $customer['name'] }}</button>
                        <span class="muted">{{ $customer['email'] }}</span>
                    </form>
                </li>
            @endforeach
        </ul>
    </main>
@endsection
