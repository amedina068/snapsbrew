<x-dashboard-layout>
  <x-slot name="pageTitle">New template</x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Choose a starting template. You get your own copy, and you can change everything in the editor.</p>

  <form action="/templates/new" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
    <div class="sm:w-72">
      <x-ui.label for="size">Size</x-ui.label>
      <x-ui.select id="size" name="size" class="mt-1">
        <option value="" {{ ! request()->filled('size') ? 'selected' : '' }}>All sizes</option>
        @foreach ($sizes as $platform => $platformSizes)
          <optgroup label="{{ $platform }}">
            @foreach ($platformSizes as $size)
              <option value="{{ $size['value'] }}" {{ request()->size == $size['value'] ? 'selected' : '' }}>{{ $size['name'] }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </x-ui.select>
    </div>
    <x-ui.button variant="secondary">Show</x-ui.button>
  </form>

  @if ($templates->isEmpty())
    <x-ui.empty-state class="mt-8" icon="rectangle-stack" title="No starting template has this size.">
      <x-slot name="actions">
        <x-ui.button size="sm" variant="secondary" href="/templates/new">Show all sizes</x-ui.button>
      </x-slot>
    </x-ui.empty-state>
  @endif

  <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
    @foreach ($templates as $template)
      <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-2">
        <img src="{{ $template->previewImage }}" class="h-44 w-full rounded-lg bg-slate-50 object-contain" alt="">
        <div class="mt-2 flex flex-1 flex-col gap-2 px-1">
          <h2 class="text-sm font-semibold text-gray-900">{{ $template->title }}</h2>
          <x-ui.badge class="max-w-full self-start">{{ $template->readableSize }}</x-ui.badge>
        </div>
        <form class="mt-3" action="/templates" method="POST">
          @csrf
          <input type="hidden" value="{{ $template->id }}" name="template_id">
          <x-ui.button size="sm" variant="secondary" class="w-full" aria-label="Use {{ $template->title }}">Use this</x-ui.button>
        </form>
      </article>
    @endforeach
  </div>
</x-dashboard-layout>
