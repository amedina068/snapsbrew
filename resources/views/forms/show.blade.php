<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div>
      {{ $form->title }} form
      <a class="focus-ring block rounded-sm text-base text-gray-600 hover:underline" href="/templates/{{ $template->uuid }}">{{ $template->title }}</a>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <x-ui.button variant="secondary" icon="pencil-square" href="/templates/{{ $template->uuid }}/forms/{{ $form->uuid }}/edit">Edit</x-ui.button>
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Here you can find the Snaps that were created using this form, and also the link you need to embed in your application to use it.</p>

  <section class="mt-10" aria-labelledby="form-link-heading">
    <h2 id="form-link-heading" class="text-xl font-semibold text-gray-900">Embedded form link</h2>
    <x-ui.copy-field class="mt-4" :value="$form->signed_url" />
  </section>

  <section class="mt-8" aria-labelledby="form-submit-link-heading">
    <h2 id="form-submit-link-heading" class="text-xl font-semibold text-gray-900">Submit link</h2>
    <p class="mt-1 text-sm text-gray-600">To build your own form, send the layer values to this URL in a POST request.</p>
    <x-ui.copy-field class="mt-4" :value="$form->submit_signed_url" />
  </section>

  <section v-pre class="mt-12" aria-labelledby="form-layers-heading">
    <h2 id="form-layers-heading" class="mb-4 text-xl font-semibold text-gray-900">Form layers</h2>
    <x-ui.table caption="Form layers">
      <x-slot name="head">
        <x-ui.th>Layer</x-ui.th>
        <x-ui.th>Type</x-ui.th>
        <x-ui.th>Default value</x-ui.th>
      </x-slot>
      @foreach ($formLayers as $formLayer)
        <tr>
          <x-ui.td strong>{{ $formLayer['key'] }}</x-ui.td>
          <x-ui.td>{{ $formLayer['type'] ?? '—' }}</x-ui.td>
          <x-ui.td>{{ $formLayer['default_value'] }}</x-ui.td>
        </tr>
      @endforeach
    </x-ui.table>
  </section>

  <section v-pre class="mt-12" aria-labelledby="form-snaps-heading">
    <h2 id="form-snaps-heading" class="mb-4 text-xl font-semibold text-gray-900">Form snaps</h2>
    @if ($snaps->count())
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($snaps as $snap)
          <article class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3">
            <div class="flex items-start justify-between gap-3 px-1 pt-1">
              <div class="min-w-0">
                <h3 class="truncate text-sm font-semibold text-gray-900">{{ $snap->identifier ?? 'Snap #'.$snap->id }}</h3>
                <p class="text-xs text-gray-500">{{ $snap->created_at->format('M d, Y H:i') }}</p>
              </div>
              <x-ui.status-badge :status="$snap->status" />
            </div>
            <x-snap-item :snap="$snap" />
            <a href="{{ $snap->imageUrl }}" target="_blank" class="focus-ring rounded-sm px-1 text-xs break-all text-rose-700 hover:text-rose-800">{{ $snap->imageUrl }}</a>
          </article>
        @endforeach
      </div>
    @else
      <x-ui.empty-state icon="photo" title="No snaps yet">No snaps have been created from this form yet.</x-ui.empty-state>
    @endif

    <div class="mt-5">
      {{ $snaps->links() }}
    </div>
  </section>
</x-dashboard-layout>
