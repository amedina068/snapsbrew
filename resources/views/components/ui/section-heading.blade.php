{{-- A section title with an optional "View all" link on the right. --}}
@props(['title', 'href' => null, 'linkLabel' => 'View all'])
<div {{ $attributes->merge(['class' => 'flex items-baseline justify-between gap-4']) }}>
  <h2 class="text-xl font-semibold text-gray-900">{{ $title }}</h2>
  @if ($href)
    <a href="{{ $href }}" class="focus-ring inline-flex items-center gap-1 rounded-sm text-sm font-semibold text-rose-700 hover:text-rose-800">
      {{ $linkLabel }}
      <x-icon name="arrow-right" class="size-4" stroke="2" />
    </a>
  @endif
</div>
