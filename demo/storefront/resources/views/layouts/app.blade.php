<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') · Ecotone demo</title>
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    </head>
    <body>
        <header class="site-header">
            <nav>
                <a href="{{ url('/') }}" class="brand">Storefront</a>
                <a href="{{ url('/concerts') }}">Concerts</a>
                <a href="{{ url('/account') }}">Account</a>
            </nav>
            <div class="customer-switcher">
                @if ($currentCustomer)
                    Shopping as <strong>{{ $currentCustomer['name'] }}</strong> · <a href="{{ url('/whoami') }}">Switch</a>
                @else
                    <a href="{{ url('/whoami') }}">Pick who you are</a>
                @endif
            </div>
        </header>
        @yield('body')
    </body>
</html>
