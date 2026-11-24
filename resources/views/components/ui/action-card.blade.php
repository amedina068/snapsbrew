{{-- A link card that suggests a next step: an icon, a title, a short text and a call to action. --}}
@props(['href', 'icon', 'title', 'cta'])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'focus-ring group flex h-full flex-col rounded-xl border border-gray-200 bg-white p-5 transition-colors hover:border-rose-300']) }}>
  <span class="flex items-center gap-3">
    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-gray-700 transition-colors group-hover:bg-rose-50 group-hover:text-rose-600">
      <x-icon :name="$icon" class="size-5" />
    </span>
    <span class="font-semibold text-gray-900">{{ $title }}</span>
  </span>
  <span class="mt-3 flex-1 text-sm text-gray-600">{{ $slot }}</span>
  <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-rose-700">
    {{ $cta }}
    <x-icon name="arrow-right" class="size-4 transition-transform group-hover:translate-x-0.5" stroke="2" />
  </span>
</a>
