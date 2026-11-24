<x-dashboard-layout>
  <x-slot name="pageTitle">Templates</x-slot>

  <x-slot name="pageActions">
    <x-ui.button icon="plus" href="/templates/new">Add new</x-ui.button>
  </x-slot>

  {{-- The message holds a template title. v-pre stops Vue from compiling it. --}}
  @if (session('status'))
    <div v-pre class="mb-6 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('status') }}</div>
  @endif

  <p class="max-w-2xl text-lg text-gray-700">These are all the templates you have created or tweaked, ordered by the most recent in descending order. Use the filters below to find templates by size, title, or tags.</p>

  <form action="/templates" class="mt-8 grid gap-4 rounded-xl border border-gray-200 bg-white p-4 md:grid-cols-3 md:items-end" role="search">
    <div>
      <x-ui.label for="query">Search</x-ui.label>
      <x-ui.input id="query" name="q" value="{{ $searchQuery }}" placeholder="Search templates or tags" class="mt-1" />
    </div>

    <div>
      <x-ui.label for="size">Size</x-ui.label>
      <x-ui.select id="size" name="size" class="mt-1">
        <option value="" {{ ! request()->filled('size') ? 'selected' : '' }}>All</option>
        @foreach ($sizes as $platform => $platformSizes)
          <optgroup label="{{ $platform }}">
            @foreach ($platformSizes as $size)
              <option value="{{ $size['value'] }}" {{ request()->size == $size['value'] ? 'selected' : '' }}>{{ $size['name'] }}</option>
            @endforeach
          </optgroup>
        @endforeach
      </x-ui.select>
    </div>

    <fieldset>
      <legend class="text-sm font-semibold text-gray-800">Tags</legend>
      <div class="mt-2 flex flex-wrap gap-2">
        @forelse ($availableTags as $tag)
          <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:border-rose-300 has-checked:border-rose-500 has-checked:bg-rose-50 has-checked:text-rose-800">
            <input
              type="checkbox"
              name="tags[]"
              value="{{ $tag->slug }}"
              class="focus-ring size-3.5 rounded border-gray-400 text-rose-600 focus:ring-0"
              {{ in_array($tag->slug, $selectedTagSlugs, true) ? 'checked' : '' }}
            >
            {{ $tag->name }}
          </label>
        @empty
          <p class="text-xs text-gray-500">No tags available yet.</p>
        @endforelse
      </div>
    </fieldset>

    <div class="flex items-center justify-end gap-2 md:col-span-3">
      <x-ui.button variant="ghost" href="/templates">Reset</x-ui.button>
      <x-ui.button variant="secondary">Apply filters</x-ui.button>
    </div>
  </form>

  @if ($templates->count())
    <div class="mt-8 grid gap-6 sm:grid-cols-2 md:grid-cols-3">
      @foreach ($templates as $template)
        <x-template-card :template="$template" />
      @endforeach
    </div>
    <div class="mt-8">
      {{ $templates->links() }}
    </div>
  @else
    @php $isFiltered = request()->filled('size') || $searchQuery || ! empty($selectedTagSlugs); @endphp
    <x-ui.empty-state class="mt-8" icon="rectangle-stack" :title="$isFiltered ? 'No templates match the current filters.' : 'You have not created any templates yet.'">
      <x-slot name="actions">
        @if ($isFiltered)
          <x-ui.button size="sm" variant="secondary" href="/templates">Clear the filters</x-ui.button>
        @else
          <x-ui.button size="sm" href="/templates/new">Create your first template</x-ui.button>
        @endif
      </x-slot>
    </x-ui.empty-state>
  @endif
</x-dashboard-layout>
