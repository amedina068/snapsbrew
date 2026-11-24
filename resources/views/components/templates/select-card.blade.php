<label class="group relative flex h-full cursor-pointer flex-col rounded-xl border border-gray-200 bg-white p-2 transition-colors hover:border-rose-300 has-checked:border-rose-500 has-checked:bg-rose-50/60 has-focus-visible:outline-3 has-focus-visible:outline-offset-2 has-focus-visible:outline-atomic-coral">
  <input
    type="{{ $type }}"
    name="{{ $name }}"
    value="{{ $template->uuid }}"
    class="peer sr-only"
    {{ $checked ? 'checked' : '' }}
  >
  <span class="relative block h-40 overflow-hidden rounded-lg bg-slate-50">
    <img src="{{ $template->previewImage }}" alt="" class="size-full object-contain">
    <x-ui.badge class="absolute top-2 left-2 bg-white/90">{{ $template->readableSize }}</x-ui.badge>
    <span class="absolute top-2 right-2 hidden size-7 place-items-center rounded-full bg-rose-600 text-white peer-checked:grid" aria-hidden="true">
      <x-icon name="check" class="size-4" stroke="2.5" />
    </span>
  </span>
  <span class="mt-2 flex flex-1 flex-col px-1 pb-1">
    <span class="text-sm font-semibold text-gray-900">{{ $template->title }}</span>
    <span class="mt-0.5 text-xs text-gray-500">Updated {{ optional($template->updated_at)->diffForHumans() }}</span>
  </span>
</label>
