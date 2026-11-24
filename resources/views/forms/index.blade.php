<x-dashboard-layout>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-3xl font-bold text-gray-900">{{ $template->title }} forms</h1>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    @if ($hasLayers)
      <x-ui.button icon="plus" href="/templates/{{ $template->uuid }}/forms/new">New form</x-ui.button>
    @else
      <x-ui.badge tone="brand">Add at least one layer to enable forms</x-ui.badge>
    @endif
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Here you will find all the forms you have created for this template. The table below shows the name, the creation date and how many Snaps have been generated using each form.</p>

  <div class="mt-10">

    @if ($forms->count())
      <x-ui.table caption="Forms">
        <x-slot name="head">
          <x-ui.th>Title</x-ui.th>
          <x-ui.th align="right">Snaps</x-ui.th>
          <x-ui.th align="right">Date created</x-ui.th>
          <x-ui.th align="right" hidden-label>View</x-ui.th>
        </x-slot>
        @foreach ($forms as $form)
          <tr>
            <x-ui.td strong>{{ $form->title }}</x-ui.td>
            <x-ui.td align="right">{{ $form->snaps_count }}</x-ui.td>
            <x-ui.td align="right" class="whitespace-nowrap">{{ $form->created_at->format('M jS, Y') }}</x-ui.td>
            <x-ui.td align="right">
              <a href="/templates/{{ $template->uuid }}/forms/{{ $form->uuid }}" class="focus-ring rounded-sm font-semibold text-rose-700 hover:text-rose-800">View</a>
            </x-ui.td>
          </tr>
        @endforeach
      </x-ui.table>
    @else
      <x-ui.empty-state icon="document-text" title="No forms yet">
        @if ($hasLayers)
          Build your first form to collect snap data from teammates or customers.
        @else
          Forms require at least one editable layer. Add layers in the editor to unlock forms.
        @endif
        <x-slot name="actions">
          @if ($hasLayers)
            <x-ui.button size="sm" href="/templates/{{ $template->uuid }}/forms/new">Create your first form</x-ui.button>
          @else
            <x-ui.button size="sm" variant="secondary" href="/templates/{{ $template->uuid }}/editor">Go to editor</x-ui.button>
          @endif
        </x-slot>
      </x-ui.empty-state>
    @endif
  </div>
</x-dashboard-layout>
