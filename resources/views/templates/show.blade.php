<x-dashboard-layout>
  <x-slot name="pageTitle">
    <div class="space-y-3">
      <x-ui.breadcrumb :items="[['label' => 'Templates', 'href' => url('templates')]]" :home-url="url('/dashboard')" />
      <div>
        <h1 class="text-lg tracking-wide text-gray-900">{{ $template->title }}</h1>
        <p class="font-mono text-xs text-gray-500">{{ $template->uuid }}</p>
        <ul class="mt-2 flex flex-wrap gap-2">
          <li><x-ui.badge><span class="sr-only">Size:</span> {{ $template->readableSize }}</x-ui.badge></li>
          <li><x-ui.badge><span class="sr-only">Dimensions:</span> {{ $template->width }} × {{ $template->height }}</x-ui.badge></li>
          <li><x-ui.badge>{{ $templateLayers->count() }} {{ Str::plural('layer', $templateLayers->count()) }}</x-ui.badge></li>
          <li><x-ui.badge tone="brand">{{ $snaps->total() }} {{ Str::plural('snap', $snaps->total()) }}</x-ui.badge></li>
        </ul>
      </div>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <div class="flex flex-wrap gap-2">
      <x-ui.button variant="secondary" icon="pencil-square" href="/templates/{{ $template->uuid }}/edit">Edit details</x-ui.button>
      <form action="/templates" method="POST">
        @csrf
        <input type="hidden" name="template_id" value="{{ $template->id }}">
        <x-ui.button variant="secondary" icon="document-duplicate">Duplicate template</x-ui.button>
      </form>
      <form action="/templates/{{ $template->uuid }}" method="POST"
            onsubmit="return confirm('Delete this template? Its snaps and forms are deleted too. You cannot undo this.')">
        @csrf
        @method('DELETE')
        <x-ui.button variant="danger">Delete template</x-ui.button>
      </form>
    </div>
  </x-slot>

  <div class="grid gap-8 md:grid-cols-2">
    <div>
      <div class="space-y-4 md:sticky md:top-5">
        <a href="/templates/{{ $template->uuid }}/editor" class="focus-ring group relative block overflow-hidden rounded-xl border border-gray-200 bg-slate-100">
          <img src="{{ $template->previewImage }}" alt="{{ $template->title }} preview" class="mx-auto block w-full">
          <span class="absolute right-3 bottom-3 inline-flex items-center gap-1.5 rounded-full bg-gray-900/80 px-3 py-1.5 text-xs font-semibold text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">
            <x-icon name="pencil" class="size-3.5" stroke="2" />
            Open editor
          </span>
        </a>

        <div class="flex flex-col gap-2 sm:flex-row">
          <x-ui.button icon="pencil" href="/templates/{{ $template->uuid }}/editor" class="flex-1">Open editor</x-ui.button>
          <x-ui.button variant="secondary" icon="bolt" href="/templates/{{ $template->uuid }}/generate" class="flex-1">Generate snap</x-ui.button>
        </div>
      </div>
    </div>

    <section aria-labelledby="layers-heading">
      <div class="mb-3 flex items-baseline justify-between">
        <h2 id="layers-heading" class="text-xl font-semibold text-gray-900">Layers</h2>
        <span class="text-sm text-gray-500">{{ $templateLayers->count() }} total</span>
      </div>

      <x-ui.table>
        <x-slot name="head">
          <x-ui.th>Layer</x-ui.th>
          <x-ui.th>Type</x-ui.th>
          <x-ui.th>Visible</x-ui.th>
          <x-ui.th align="right">Position</x-ui.th>
        </x-slot>
        @forelse ($templateLayers as $key => $layer)
          <tr>
            <x-ui.td strong>{{ $layer['key'] ?? '—' }}</x-ui.td>
            <x-ui.td>{{ $layer['type'] ?? '—' }}</x-ui.td>
            <x-ui.td>{{ ($layer['visible'] ?? true) ? 'Yes' : 'No' }}</x-ui.td>
            <x-ui.td align="right">{{ $key + 1 }}</x-ui.td>
          </tr>
        @empty
          <tr>
            <x-ui.td colspan="4" class="py-8 text-center">
              This template has no layers. <a href="/templates/{{ $template->uuid }}/editor" class="font-semibold text-rose-700 underline underline-offset-2">Open the editor</a> to add some.
            </x-ui.td>
          </tr>
        @endforelse
      </x-ui.table>
    </section>
  </div>

  <div class="mt-12 grid gap-6 lg:grid-cols-2">
    <x-ui.panel title="Forms" description="Create shareable forms to collect data for this template.">
      <x-slot name="actions">
        <x-ui.button variant="ghost" size="sm" href="/templates/{{ $template->uuid }}/forms">Manage</x-ui.button>
      </x-slot>

      @if ($forms->count())
        <ul class="space-y-2">
          @foreach ($forms as $form)
            <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5">
              <div class="min-w-0">
                <p class="truncate font-medium text-gray-900">{{ $form->title }}</p>
                <p class="text-xs text-gray-500">Updated {{ $form->updated_at->format('M d, Y') }}</p>
              </div>
              <a href="/templates/{{ $template->uuid }}/forms/{{ $form->uuid }}" class="focus-ring shrink-0 rounded-sm text-sm font-semibold text-rose-700 hover:text-rose-800">View</a>
            </li>
          @endforeach
        </ul>
      @else
        <x-ui.empty-state bare icon="document-text" title="No forms yet">
          Create a form to let teammates or customers submit snap data.
          <x-slot name="actions">
            <x-ui.button size="sm" variant="secondary" href="/templates/{{ $template->uuid }}/forms/new">Create form</x-ui.button>
          </x-slot>
        </x-ui.empty-state>
      @endif
    </x-ui.panel>

    <x-ui.panel title="Imports" description="Watch the latest spreadsheet-driven batches for this template.">
      <x-slot name="actions">
        <x-ui.button variant="ghost" size="sm" href="/templates/{{ $template->uuid }}/imports">Manage</x-ui.button>
      </x-slot>

      @if ($latestImports->count())
        <ul class="space-y-2">
          @foreach ($latestImports as $import)
            <li class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2.5">
              <div class="min-w-0">
                <p class="truncate font-medium text-gray-900">{{ $import->title ?? 'Untitled import' }}</p>
                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                  <x-ui.status-badge :status="$import->status" />
                  {{ $import->created_at->format('M d, Y H:i') }}
                </p>
              </div>
              <a href="/templates/{{ $template->uuid }}/imports/{{ $import->uuid }}" class="focus-ring shrink-0 rounded-sm text-sm font-semibold text-rose-700 hover:text-rose-800">View</a>
            </li>
          @endforeach
        </ul>
      @else
        <x-ui.empty-state bare icon="arrow-up-tray" title="No imports yet">
          Upload a spreadsheet to generate snaps in bulk.
          <x-slot name="actions">
            <x-ui.button size="sm" variant="secondary" href="/templates/{{ $template->uuid }}/imports/create">Start an import</x-ui.button>
          </x-slot>
        </x-ui.empty-state>
      @endif
    </x-ui.panel>
  </div>

  <section class="mt-12" aria-labelledby="snaps-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="snaps-heading" class="text-xl font-semibold text-gray-900">Snaps</h2>
      <div class="flex flex-wrap items-center gap-2">
        <x-ui.button icon="bolt" href="/templates/{{ $template->uuid }}/generate">Generate snap</x-ui.button>
        @if ($snaps->count())
          <form id="delete-several-form" onsubmit="return confirm('Delete the selected snaps? You cannot undo this.')" action="/snapshots" method="POST">
            @csrf
            @method('DELETE')
            <x-ui.button variant="danger">Delete selected</x-ui.button>
          </form>
        @endif
      </div>
    </div>

    @error('snaps_ids')
      <p class="mt-3 text-sm text-red-700" role="alert">{{ $message }}</p>
    @enderror

    <div class="mt-6">
      @if ($snaps->count())
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          @foreach ($snaps as $snap)
            @php $snapName = $snap->identifier ?? 'Snap #'.$snap->id; @endphp
            <article class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3">
              <div class="flex items-start justify-between gap-3 px-1 pt-1">
                <div class="min-w-0">
                  <h3 class="truncate text-sm font-semibold text-gray-900">{{ $snapName }}</h3>
                  <p class="text-xs text-gray-500">{{ $snap->created_at->format('M d, Y H:i') }}</p>
                </div>
                <input
                  type="checkbox"
                  name="snaps_ids[]"
                  value="{{ $snap->uuid }}"
                  form="delete-several-form"
                  aria-label="Select {{ $snapName }}"
                  class="focus-ring size-4 rounded border-gray-400 text-rose-600 focus:ring-0"
                >
              </div>

              <x-snap-item :snap="$snap" />

              <div class="flex items-center justify-between gap-3 px-1">
                @if ($snap->isComplete() && $snap->imageUrl)
                  <x-ui.button size="sm" variant="secondary" href="{{ $snap->imageUrl }}" target="_blank">Open image</x-ui.button>
                @else
                  <span class="text-xs font-medium text-gray-500">Image not available yet</span>
                @endif
                <form action="/snapshots/{{ $snap->uuid }}" method="POST"
                      onsubmit="return confirm('Delete this snap? You cannot undo this.')">
                  @csrf
                  @method('DELETE')
                  <x-ui.button size="sm" variant="danger-ghost" aria-label="Delete {{ $snapName }}">Delete</x-ui.button>
                </form>
              </div>
            </article>
          @endforeach
        </div>
      @else
        <x-ui.empty-state icon="photo" title="No snaps yet">
          This template does not have any snaps.
          <x-slot name="actions">
            <x-ui.button size="sm" href="/templates/{{ $template->uuid }}/generate">Generate your first snap</x-ui.button>
          </x-slot>
        </x-ui.empty-state>
      @endif
    </div>

    @if ($snaps->hasPages())
      <div class="mt-8">
        {{ $snaps->links() }}
      </div>
    @endif
  </section>
</x-dashboard-layout>
