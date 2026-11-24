<x-dashboard-layout>
  <x-slot name="pageTitle">
    Add template to collection {{ $templateCollection->name }}
  </x-slot>

  <form action="/collections/{{ $templateCollection->uuid }}/templates" method="POST">
    @csrf

    @if ($templates->isEmpty())
      <x-ui.empty-state icon="rectangle-stack" title="There are no templates available to add.">
        Create a template first.
        <x-slot name="actions">
          <x-ui.button size="sm" variant="secondary" href="/templates/new">Create a template</x-ui.button>
        </x-slot>
      </x-ui.empty-state>
    @else
      <fieldset>
        <legend class="sr-only">Templates</legend>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          @foreach ($templates as $template)
            <x-templates.select-card
              :template="$template"
              name="template_ids[]"
              :checked="in_array($template->uuid, $selectedTemplateUuids, true)"
              type="checkbox"
            />
          @endforeach
        </div>
      </fieldset>

      @error('template_ids')
        <p class="mt-4 text-sm text-red-700">{{ $message }}</p>
      @enderror
      @foreach ($errors->get('template_ids.*', []) as $message)
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
      @endforeach

      <x-ui.button class="mt-6">Add templates</x-ui.button>
    @endif
  </form>
</x-dashboard-layout>
