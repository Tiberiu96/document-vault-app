@props(['href' => null, 'variant' => 'primary', 'icon' => null])
@if($href)
    <a href="{{ $href }}" {{ $attributes->class(['button', 'button-'.$variant]) }}>{{ $slot }} @if($icon)<x-icon :name="$icon" :size="18"/>@endif</a>
@else
    <button {{ $attributes->class(['button', 'button-'.$variant]) }}>{{ $slot }} @if($icon)<x-icon :name="$icon" :size="18"/>@endif</button>
@endif
