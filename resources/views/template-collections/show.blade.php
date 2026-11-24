<x-dashboard-layout>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Collections', 'href' => url('collections')],
          ['label' => $templateCollection->name, 'current' => true],
        ]"
        :home-url="url('/dashboard')"
      />
      <div>
        <h1 class="text-lg tracking-wide text-gray-900">{{ $templateCollection->name }}</h1>
        <span class="font-mono text-xs text-gray-500">{{ $templateCollection->uuid }}</span>
      </div>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <form action="/collections/{{ $templateCollection->uuid }}" method="POST"
          onsubmit="return confirm('Delete this collection? Its forms are deleted too. The templates stay.')">
      @csrf
      @method('DELETE')
      <x-ui.button variant="danger">Delete collection</x-ui.button>
    </form>
  </x-slot>


  <div class="mt-10 space-y-14">
    <section aria-labelledby="collection-templates-heading">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="collection-templates-heading" class="text-xl font-semibold text-gray-900">Templates</h2>
          <p class="text-sm text-gray-500">Curate the designs that belong to this collection.</p>
        </div>
        <x-ui.button variant="secondary" icon="plus" href="/collections/{{ $templateCollection->uuid }}/templates/new">Add template</x-ui.button>
      </div>
      <div class="mt-5">
        @if ($templates->count())
          <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($templates as $template)
              <x-template-card :template="$template">
                <form action="/collections/{{ $templateCollection->uuid }}/templates/{{ $template->uuid }}" method="POST"
                      onsubmit="return confirm('Remove this template from the collection? The template stays.')">
                  @csrf
                  @method('DELETE')
                  <x-ui.button size="sm" variant="danger-ghost" class="w-full">Remove from collection</x-ui.button>
                </form>
              </x-template-card>
            @endforeach
          </div>
        @else
          <x-ui.empty-state icon="rectangle-stack" title="This collection is empty">
            Add templates to curate a set you can reuse across projects.
            <x-slot name="actions">
              <x-ui.button size="sm" href="/collections/{{ $templateCollection->uuid }}/templates/new">Add your first template</x-ui.button>
            </x-slot>
          </x-ui.empty-state>
        @endif
      </div>
    </section>

    <section aria-labelledby="collection-forms-heading">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="collection-forms-heading" class="text-xl font-semibold text-gray-900">Forms</h2>
          <p class="text-sm text-gray-500">Shareable forms let teammates submit data for all templates in this collection.</p>
        </div>
        <x-ui.button variant="secondary" icon="plus" href="/collections/{{ $templateCollection->uuid }}/forms/new">Add form</x-ui.button>
      </div>
      <div class="mt-5">
        @if ($forms->count())
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($forms as $form)
              <article class="flex flex-col rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h3 class="truncate text-sm font-semibold text-gray-900">{{ $form->title }}</h3>
                    <p class="text-xs text-gray-500">Updated {{ $form->updated_at->format('M d, Y') }}</p>
                  </div>
                  <x-ui.badge>{{ strtoupper($form->scope()) }}</x-ui.badge>
                </div>
                <p class="mt-3 line-clamp-2 font-mono text-xs break-all text-gray-500">{{ $form->signed_url }}</p>
                <div class="mt-4 flex items-center gap-2">
                  <x-ui.button size="sm" variant="secondary" href="/collections/{{ $templateCollection->uuid }}/forms/{{ $form->uuid }}">Open</x-ui.button>
                  <x-ui.button size="sm" variant="ghost" href="/collections/{{ $templateCollection->uuid }}/forms/{{ $form->uuid }}/edit">Edit</x-ui.button>
                </div>
              </article>
            @endforeach
          </div>
        @else
          <x-ui.empty-state icon="document-text" title="No forms yet">
            Create a form to collect snap data for every template in this collection.
            <x-slot name="actions">
              <x-ui.button size="sm" variant="secondary" href="/collections/{{ $templateCollection->uuid }}/forms/new">Add your first form</x-ui.button>
            </x-slot>
          </x-ui.empty-state>
        @endif
      </div>
    </section>

    <section aria-labelledby="collection-snaps-heading">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 id="collection-snaps-heading" class="text-xl font-semibold text-gray-900">Snaps</h2>
          <p class="text-sm text-gray-500">Recent assets generated from the templates inside this collection.</p>
        </div>
        <x-ui.button variant="secondary" href="/snapshots">View all snaps</x-ui.button>
      </div>
      <div class="mt-5">
        @if ($snaps->count())
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($snaps as $snap)
              <x-snap-item :snap="$snap" :link="url('snapshots/'.$snap->uuid)" />
            @endforeach
          </div>
        @else
          <x-ui.empty-state icon="photo" title="No snaps yet">Run a form or import to generate assets from these templates.</x-ui.empty-state>
        @endif
      </div>
    </section>
  </div>
</x-dashboard-layout>
