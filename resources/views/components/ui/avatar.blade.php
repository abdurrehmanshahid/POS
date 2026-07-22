@props(['name' => '', 'variant' => 'default', 'size' => 30])

@php $cls = $variant === 'navy' ? 'avatar-navy' : ($variant === 'orange' ? 'avatar-orange' : ''); @endphp

<div {{ $attributes->merge(['class' => "avatar $cls"]) }}
     style="width:{{ $size }}px;height:{{ $size }}px;font-size:{{ round($size * 0.38) }}px">
    {{ \App\Support\Format::initials($name) }}
</div>
