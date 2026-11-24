{{--
  A white card with an optional header. The header shows the title, the
  description and the `actions` slot.
--}}
@props(['title' => null, 'description' => null, 'flush' => false])
<section {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white']) }}>
  @if ($title || isset($actions))
    <header class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
      <div class="min-w-0">
        @if ($title)
          <h2 class="text-base font-semibold text-gray-900">{{ $title }}</h2>
        @endif
        @if ($description)
          <p class="mt-0.5 text-sm text-gray-500">{{ $description }}</p>
        @endif
      </div>
      @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
      @endisset
    </header>
  @endif
  <div @class(['p-5' => ! $flush])>{{ $slot }}</div>
</section>
