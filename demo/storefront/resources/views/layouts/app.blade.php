<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @yield('head')
        <title>@yield('title') · Stagefront</title>
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    </head>
    <body>
        <a class="skip-link" href="#content">Skip to content</a>
        <header class="site-header">
            <div class="site-header__inner">
                <a href="{{ url('/') }}" class="brand"><span class="brand-mark"><x-icon name="logo"/></span>Stagefront</a>
                <nav class="site-nav" aria-label="Main">
                    <a href="{{ url('/concerts') }}"><x-icon name="music"/>Concerts</a>
                    <a href="{{ url('/account') }}"><x-icon name="logo"/>My tickets</a>
                </nav>
                <div class="customer-switcher customer-chip">
                    @if ($currentCustomer)
                        <x-icon name="user"/><span>Shopping as <strong>{{ $currentCustomer['name'] }}</strong></span> <a href="{{ url('/whoami') }}">Switch</a>
                    @else
                        <a href="{{ url('/whoami') }}"><x-icon name="user"/>Pick who you are</a>
                    @endif
                </div>
            </div>
        </header>
        @yield('hero')
        <main class="page" id="content">
            @yield('body')
        </main>
        <footer class="site-footer">
            <div class="site-footer__inner">
                <p>Stagefront is the storefront of the Ecotone demo: every seat is held, sold and ticketed by the ticketing service over the Distributed Bus.</p>
                <ul>
                    <li><a href="{{ config('demo.pulse_url') }}">Ecotone Pulse</a></li>
                    <li><a href="{{ config('demo.jaeger_url') }}/search?service=storefront">Jaeger traces</a></li>
                </ul>
            </div>
        </footer>
    </body>
</html>
