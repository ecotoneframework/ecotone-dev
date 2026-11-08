@props(['name'])
<svg {{ $attributes->merge(['class' => 'icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('logo')
            <path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v12" stroke-dasharray="2 2"/>
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>
            @break
        @case('building')
            <path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10M2 21h20M8 7h4M8 11h4M8 15h4"/>
            @break
        @case('seats')
            <path d="M5 11V7a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4"/><path d="M3 11h18v4H3zM5 15v4M19 15v4"/>
            @break
        @case('pulse')
            <path d="M3 12h4l3-8 4 16 3-8h4"/>
            @break
        @case('trace')
            <path d="M4 6h10M8 12h12M6 18h8"/><circle cx="17" cy="6" r="1"/><circle cx="5" cy="12" r="1"/><circle cx="17" cy="18" r="1"/>
            @break
        @case('check')
            <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
            @break
        @case('alert')
            <path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>
            @break
        @case('play')
            <path d="m7 4 13 8-13 8z"/>
            @break
        @case('arrow')
            <path d="M5 12h14M13 6l6 6-6 6"/>
            @break
        @case('music')
            <path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('user')
            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
            @break
        @case('star')
            <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>
            @break
        @case('flask')
            <path d="M9 3h6M10 3v6L4 19a1 1 0 0 0 1 2h14a1 1 0 0 0 1-2l-6-10V3"/><path d="M7 15h10"/>
            @break
    @endswitch
</svg>
