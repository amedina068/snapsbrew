{{--
  The app button. It is a pill, as in the guest pages and the account menu.
  With `href` it renders a link. Without `href` it renders a <button>.
  Variants: primary, secondary, danger, ghost, danger-ghost. Sizes: sm, md.
--}}
@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'submit',
    'icon' => null,
])
@php
    $variants = [
        'primary' => 'border-rose-700 bg-rose-600 text-white hover:bg-rose-700',
        'secondary' => 'border-gray-300 bg-white text-gray-800 hover:border-gray-400 hover:bg-slate-50',
        'danger' => 'border-red-200 bg-white text-red-700 hover:border-red-300 hover:bg-red-50',
        'ghost' => 'border-transparent bg-transparent text-gray-600 hover:bg-slate-100 hover:text-gray-900',
        'danger-ghost' => 'border-transparent bg-transparent text-red-700 hover:bg-red-50 hover:text-red-800',
    ];
    $sizes = [
        'sm' => 'min-h-8 gap-1.5 px-3 text-xs',
        'md' => 'min-h-10 gap-2 px-5 text-sm',
    ];
    $classes = 'focus-ring inline-flex shrink-0 items-center justify-center rounded-full border font-semibold whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-50 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp
@if ($href)
  <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)<x-icon :name="$icon" class="size-4" stroke="2" />@endif
    {{ $slot }}
  </a>
@else
  <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
    @if ($icon)<x-icon :name="$icon" class="size-4" stroke="2" />@endif
    {{ $slot }}
  </button>
@endif
