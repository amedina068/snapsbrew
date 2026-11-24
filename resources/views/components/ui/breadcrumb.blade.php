{{--
  The trail of parent pages above a page title.
  Each item is ['label' => ..., 'href' => ..., 'current' => bool].
--}}
@props([
    'items' => [],
    'homeUrl' => url('/'),
])
<nav aria-label="Breadcrumb" {{ $attributes }}>
  <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500">
    <li>
      <a href="{{ $homeUrl }}" class="focus-ring rounded-sm hover:text-rose-700">Home</a>
    </li>
    @foreach ($items as $item)
      <li class="flex min-w-0 items-center gap-2">
        <span class="text-gray-300" aria-hidden="true">/</span>
        @if (! ($item['current'] ?? false) && ($item['href'] ?? false))
          <a href="{{ $item['href'] }}" class="focus-ring truncate rounded-sm hover:text-rose-700">{{ $item['label'] ?? '' }}</a>
        @else
          <span class="truncate font-medium text-gray-700" @if ($item['current'] ?? false) aria-current="page" @endif>{{ $item['label'] ?? '' }}</span>
        @endif
      </li>
    @endforeach
  </ol>
</nav>
