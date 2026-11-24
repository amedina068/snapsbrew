<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div>
      {{ $form->title }} form
      <a class="focus-ring block rounded-sm text-base text-gray-600 hover:underline" href="/collections/{{ $templateCollection->uuid }}">{{ $templateCollection->name }}</a>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <x-ui.button variant="secondary" icon="pencil-square" href="/collections/{{ $templateCollection->uuid }}/forms/{{ $form->uuid }}/edit">Edit</x-ui.button>
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Share the signed URL below to let teammates submit requests. Each submission generates snaps across the selected templates in this collection.</p>

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
    <h2 id="form-layers-heading" class="text-xl font-semibold text-gray-900">Form layers</h2>
    <div class="mt-4 space-y-6">
      @forelse ($formLayerSummaries as $summary)
        <x-ui.panel :title="$summary['template']->title" :description="$summary['template']->uuid">
          <x-ui.table :caption="'Layers of '.$summary['template']->title">
            <x-slot name="head">
              <x-ui.th>Layer</x-ui.th>
              <x-ui.th>Type</x-ui.th>
              <x-ui.th>Default value</x-ui.th>
            </x-slot>
            @foreach ($summary['layers'] as $layer)
              <tr>
                <x-ui.td strong>{{ $layer['key'] }}</x-ui.td>
                <x-ui.td>{{ $layer['type'] ?? '—' }}</x-ui.td>
                <x-ui.td>{{ $layer['default_value'] }}</x-ui.td>
              </tr>
            @endforeach
          </x-ui.table>
        </x-ui.panel>
      @empty
        <p class="text-sm text-gray-500">This form is not currently linked to any template layers.</p>
      @endforelse
    </div>
  </section>

  <section v-pre class="mt-12" aria-labelledby="form-snaps-heading">
    <h2 id="form-snaps-heading" class="mb-4 text-xl font-semibold text-gray-900">Form snaps</h2>
    <x-ui.table caption="Form snaps">
      <x-slot name="head">
        <x-ui.th>Template</x-ui.th>
        <x-ui.th>Image</x-ui.th>
        <x-ui.th>URL</x-ui.th>
        <x-ui.th align="right">Date created</x-ui.th>
      </x-slot>
      @forelse ($snaps as $snap)
        <tr>
          <x-ui.td strong>
            {{ optional($snap->template)->title }}
            <span class="block text-xs font-normal text-gray-500">{{ optional($snap->template)->uuid }}</span>
          </x-ui.td>
          <x-ui.td>
            <img class="size-10 rounded border border-gray-200 object-contain" src="{{ $snap->imageUrl }}" alt="">
          </x-ui.td>
          <x-ui.td class="break-all">
            <a href="{{ $snap->imageUrl }}" target="_blank" class="focus-ring rounded-sm text-rose-700 hover:text-rose-800">{{ $snap->imageUrl }}</a>
          </x-ui.td>
          <x-ui.td align="right" class="whitespace-nowrap">{{ $snap->created_at->format('M jS, Y') }}</x-ui.td>
        </tr>
      @empty
        <tr><x-ui.td colspan="4" class="py-8 text-center">This form has not generated any snaps yet. Share the embedded form link to create new requests.</x-ui.td></tr>
      @endforelse
    </x-ui.table>

    @if ($snaps->hasPages())
      <div class="mt-5">
        {{ $snaps->links() }}
      </div>
    @endif
  </section>
</x-dashboard-layout>
