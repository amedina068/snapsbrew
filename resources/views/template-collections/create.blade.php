<x-dashboard-layout>
  <x-slot name="pageTitle">
    Create Collection
  </x-slot>

  <form action="/collections" method="POST" class="space-y-8">
    @csrf
    <div class="max-w-xl">
      <x-ui.label for="name">Title</x-ui.label>
      <x-ui.input id="name" name="name" value="{{ old('name') }}" required class="mt-1" />
      @error('name')
        <p class="mt-2 text-sm text-red-700">{{ $message }}</p>
      @enderror
    </div>

    <fieldset>
      <legend class="text-lg font-semibold text-gray-900">Templates to include</legend>
      <p class="mt-1 text-sm text-gray-500">Pick one or more templates to pre-fill this collection. You can always add more later.</p>

      @if ($templates->isEmpty())
        <x-ui.empty-state class="mt-5" icon="rectangle-stack" title="You do not have any templates yet.">
          Create a template to add it to a collection.
          <x-slot name="actions">
            <x-ui.button size="sm" variant="secondary" href="/templates/new">Create a template</x-ui.button>
          </x-slot>
        </x-ui.empty-state>
      @else
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          @foreach ($templates as $template)
            <x-templates.select-card
              :template="$template"
              name="template_ids[]"
              :checked="in_array($template->uuid, $selectedTemplateUuids, true)"
              type="checkbox"
            />
          @endforeach
        </div>
      @endif
    </fieldset>

    @error('template_ids')
      <p class="text-sm text-red-700">{{ $message }}</p>
    @enderror
    @foreach ($errors->get('template_ids.*', []) as $message)
      <p class="text-sm text-red-700">{{ $message }}</p>
    @endforeach

    <x-ui.button>Create</x-ui.button>
  </form>
</x-dashboard-layout>
