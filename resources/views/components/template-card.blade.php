<article class="flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white transition-colors hover:border-rose-300">
  <div class="relative bg-slate-50">
    <a href="/templates/{{ $template->uuid }}" class="focus-ring block" tabindex="-1">
      @if ($template->previewImage)
        <img src="{{ $template->previewImage }}" class="h-56 w-full object-contain" alt="Preview of {{ $template->title }}">
      @else
        <span class="flex h-56 w-full items-center justify-center px-6 text-center text-sm text-gray-500">The preview is on its way.</span>
      @endif
    </a>
    <x-ui.badge class="absolute top-3 left-3 bg-white/90">{{ $template->readableSize }}</x-ui.badge>
    <button
      type="button"
      class="focus-ring absolute top-2 right-2 grid size-9 place-items-center rounded-full bg-white/90 text-gray-600 hover:bg-white hover:text-gray-900"
      onclick='window.emitter.emit("open-template-actions", @json($template))'
      aria-label="Actions for {{ $template->title }}"
    >
      <x-icon name="ellipsis-vertical" class="size-5" stroke="2" />
    </button>
  </div>

  <div class="flex flex-1 flex-col gap-4 border-t border-gray-100 p-4">
    <div class="flex-1">
      <h3>
        <a href="/templates/{{ $template->uuid }}" class="focus-ring rounded-sm font-semibold text-gray-900 hover:text-rose-700">{{ $template->title }}</a>
      </h3>
      @if ($template->tags->isNotEmpty())
        <ul class="mt-2 flex flex-wrap gap-1.5" aria-label="Tags">
          @foreach ($template->tags as $tag)
            <li class="rounded-full border border-gray-200 px-2 py-0.5 text-[11px] font-medium text-gray-600">#{{ $tag->name }}</li>
          @endforeach
        </ul>
      @endif
    </div>
    <x-ui.button size="sm" icon="bolt" href="/templates/{{ $template->uuid }}/generate">Generate snap</x-ui.button>
    {{ $slot }}
  </div>
</article>
