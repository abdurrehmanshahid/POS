@props(['tone' => 'iris', 'dot' => false])

<span {{ $attributes->merge(['class' => "pill pill-$tone"]) }}>
    @if ($dot)<span class="dot"></span>@endif{{ $slot }}
</span>
