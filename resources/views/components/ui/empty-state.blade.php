{{--
  The message for a list that has no items yet. The icon and the text sit
  in one row, and the `actions` slot sits on the right. On a phone the
  parts stack. Use `bare` inside a panel that already has a border.
--}}
@props(['title', 'icon' => 'inbox-stack', 'bare' => false])
@php
    $frame = $bare ? '' : ' rounded-xl border border-gray-200 border-l-4 border-l-rose-400 bg-white px-5 py-5';
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 sm:flex-row sm:items-center'.$frame]) }}>
  <span class="grid size-10 shrink-0 place-items-center rounded-full bg-rose-50 text-rose-600">
    <x-icon :name="$icon" class="size-5" />
  </span>
  <div class="min-w-0 flex-1">
    <p class="font-semibold text-gray-900">{{ $title }}</p>
    @if ($slot->isNotEmpty())
      <div class="mt-1 text-sm text-gray-600">{{ $slot }}</div>
    @endif
  </div>
  @isset($actions)
    <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
  @endisset
</div>
