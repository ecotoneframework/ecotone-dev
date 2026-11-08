@extends('layouts.app')

@section('title', 'Who are you?')

@section('body')
    <header class="page-title">
        <p class="eyebrow"><x-icon name="user"/>No login needed</p>
        <h1>Who are you?</h1>
        <p class="muted">The demo has no login: pick a customer and the storefront remembers your choice in a cookie.</p>
    </header>
    @if ($customers === [])
        <div class="empty-state">
            <p>No customers yet. Run <code>php artisan demo:seed</code> in the storefront container to register the demo customers.</p>
        </div>
    @endif
    <ul class="customer-cards customer-list">
        @foreach ($customers as $customer)
            <li class="card customer-card">
                <form method="post" action="{{ url('/whoami') }}">
                    @csrf
                    <input type="hidden" name="customerId" value="{{ $customer['customerId'] }}">
                    <span class="avatar" aria-hidden="true">{{ \App\Web\Labels::initials($customer['name']) }}</span>
                    <span class="customer-card__name"><strong>{{ $customer['name'] }}</strong><span class="muted">{{ $customer['email'] }}</span></span>
                    <button type="submit" class="button button--secondary" aria-label="Shop as {{ $customer['name'] }}">Shop as {{ $customer['name'] }}</button>
                </form>
            </li>
        @endforeach
    </ul>
@endsection
