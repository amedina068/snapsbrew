<x-dashboard-layout>
  <x-slot name="pageTitle">
    {{ $templateCollection->name }} forms
  </x-slot>

  <x-slot name="pageActions">
    @if ($hasLayers)
      <x-ui.button variant="secondary" icon="plus" href="/collections/{{ $templateCollection->uuid }}/forms/new">New form</x-ui.button>
    @else
      <x-ui.badge>Add layers to at least one template to enable forms</x-ui.badge>
    @endif
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Forms tied to this collection let you generate snaps across multiple templates in one submission. Each form tracks how often it has been used.</p>

  <div class="mt-10">

    <x-ui.table caption="Collection forms">
      <x-slot name="head">
        <x-ui.th>Title</x-ui.th>
        <x-ui.th align="right">Snaps</x-ui.th>
        <x-ui.th align="right">Date created</x-ui.th>
        <x-ui.th align="right" hidden-label>View</x-ui.th>
      </x-slot>
      @forelse ($forms as $form)
        <tr>
          <x-ui.td strong>{{ $form->title }}</x-ui.td>
          <x-ui.td align="right">{{ $form->snaps_count }}</x-ui.td>
          <x-ui.td align="right" class="whitespace-nowrap">{{ $form->created_at->format('M jS, Y') }}</x-ui.td>
          <x-ui.td align="right">
            <a href="/collections/{{ $templateCollection->uuid }}/forms/{{ $form->uuid }}" class="focus-ring rounded-sm font-semibold text-rose-700 hover:text-rose-800">View</a>
          </x-ui.td>
        </tr>
      @empty
        <tr>
          <x-ui.td colspan="4" class="py-8 text-center">
            @if ($hasLayers)
              This collection does not have any forms yet. <a href="/collections/{{ $templateCollection->uuid }}/forms/new" class="font-semibold text-rose-700 underline underline-offset-2">Create a form</a> to power requests across the templates in this collection.
            @else
              Forms require at least one template in the collection to include layers. <a href="/collections/{{ $templateCollection->uuid }}" class="font-semibold text-rose-700 underline underline-offset-2">Add templates with layers</a> before creating a form.
            @endif
          </x-ui.td>
        </tr>
      @endforelse
    </x-ui.table>

    @if ($forms->hasPages())
      <div class="mt-8">
        {{ $forms->links() }}
      </div>
    @endif
  </div>
</x-dashboard-layout>
