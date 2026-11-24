<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">Template Collections</x-slot>

  <x-slot name="pageActions">
    <x-ui.button icon="plus" href="/collections/new">Add new</x-ui.button>
  </x-slot>

  {{-- The message holds a collection name. v-pre stops Vue from compiling it. --}}
  @if (session('status'))
    <div v-pre class="mt-4 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('status') }}</div>
  @endif

  <div class="mt-8 space-y-6">
    <form v-pre action="/collections" method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end" role="search">
      <div class="sm:w-80">
        <x-ui.label for="collection-search">Search</x-ui.label>
        <x-ui.input id="collection-search" name="q" value="{{ $search }}" placeholder="Search collections, templates, or tags" class="mt-1" />
      </div>
      <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Search</x-ui.button>
        @if ($search)
          <x-ui.button variant="ghost" href="/collections">Reset</x-ui.button>
        @endif
      </div>
    </form>

    @if ($templateCollections->count())
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($templateCollections as $templateCollection)
          <collection-item :collection='@json($templateCollection)' class="h-full"></collection-item>
        @endforeach
      </div>
    @else
      <x-ui.empty-state v-pre icon="folder-open" :title="$search ? 'No template collections match your current search.' : 'You have not created any template collections yet.'">
        @if ($search)
          Try adjusting the search filters or start a new collection to group related templates.
        @else
          Collections help you organize templates by campaign, channel, or client.
        @endif
        <x-slot name="actions">
          <x-ui.button size="sm" href="/collections/new">Create a collection</x-ui.button>
        </x-slot>
      </x-ui.empty-state>
    @endif
  </div>
</x-dashboard-layout>
