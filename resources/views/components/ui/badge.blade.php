{{--
  A small status label with a colored dot.
  Tones: neutral, brand, success, warning, danger, info.
--}}
@props(['tone' => 'neutral'])
@php
    $tones = [
        'neutral' => 'bg-slate-100 text-slate-700',
        'brand' => 'bg-rose-50 text-rose-800',
        'success' => 'bg-emerald-50 text-emerald-800',
        'warning' => 'bg-amber-50 text-amber-900',
        'danger' => 'bg-red-50 text-red-800',
        'info' => 'bg-sky-50 text-sky-900',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap '.($tones[$tone] ?? $tones['neutral'])]) }}>
  <span class="size-1.5 rounded-full bg-current opacity-70" aria-hidden="true"></span>
  {{ $slot }}
</span>
