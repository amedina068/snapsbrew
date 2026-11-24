<x-dashboard-layout>
  <x-slot name="pageTitle">
    Edit form {{ $form->title }}
  </x-slot>

  <div>
      <div class="text-lg text-gray-700 max-w-2xl">
          <p>Update the title, format, or editable layers for this collection form. Existing snaps generated from the form are not affected.</p>
      </div>
      <div class="mt-12">
        <form action="/collections/{{ $templateCollection->uuid }}/forms/{{ $form->uuid }}" method="POST" class="mt-6 space-y-8">
            @csrf
            @method('PATCH')

            <div class="max-w-xl">
                <x-ui.label for="title">Title</x-ui.label>
                <div class="mt-2">
                    <x-ui.input required maxlength="255" name="title" id="title" :aria-describedby="$errors->has('title') ? 'title-error' : null" value="{{ old('title', $form->title) }}"/>
                    @error('title')
                        <p id="title-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <fieldset @error('form_layers') aria-describedby="form-layers-error" @enderror>
                <legend class="text-sm font-semibold text-gray-800">Templates and layers</legend>
                <p class="mt-1 text-sm text-gray-500">Adjust the editable layers for each template in the collection.</p>

            @error('form_layers')
                <p id="form-layers-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <div class="mt-4 space-y-6">
                @foreach($templatesWithLayers as $templateData)
                    @php
                        $template = $templateData['template'];
                        $layers = $templateData['layers'];
                        $currentSelection = old('form_layers') !== null
                            ? array_keys(old("form_layers.{$template->uuid}", []))
                            : ($selectedLayers[$template->uuid] ?? []);
                    @endphp
                    <div class="rounded-lg border border-gray-200 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">{{ $template->title }}</h3>
                                <p class="text-sm text-gray-500">{{ $template->uuid }}</p>
                            </div>
                            @if(empty($layers))
                                <span class="inline-flex items-center rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">No layers available</span>
                            @endif
                        </div>

                        @if(! empty($layers))
                            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                                @foreach($layers as $layer)
                                    <div>
                                        <label for="layer_{{ $template->uuid }}_{{ $layer['key'] }}" class="inline-flex space-x-2 items-center">
                                            <input type="checkbox" class="h-5 w-5 rounded-md" name="form_layers[{{ $template->uuid }}][{{ $layer['key'] }}]" id="layer_{{ $template->uuid }}_{{ $layer['key'] }}" {{ in_array($layer['key'], $currentSelection) ? 'checked' : '' }}>
                                            <span>
                                                {{ $layer['key'] }}
                                                <span class="text-sm text-gray-500 font-semibold">({{ $layer['type'] ?? '—' }})</span>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            </fieldset>

            <fieldset>
                <legend class="text-sm font-semibold text-gray-800">Format</legend>
                <div class="mt-2 flex space-x-4">
                    <label class="inline-flex space-x-2 items-center" for="type_image">
                        <input name="type" {{ old('type', $form->type) == 'image' ? 'checked' : '' }} id="type_image" type="radio" value="image">
                        <span>Image</span>
                    </label>
                    <label class="inline-flex space-x-2 items-center" for="type_pdf">
                        <input name="type" {{ old('type', $form->type) == 'pdf' ? 'checked' : '' }} id="type_pdf" type="radio" value="pdf">
                        <span>PDF</span>
                    </label>
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-3">
                <x-ui.button>Save form</x-ui.button>
                <x-ui.button variant="secondary" href="{{ url('collections/'.$templateCollection->uuid.'/forms/'.$form->uuid) }}">Cancel</x-ui.button>
            </div>
        </form>
      </div>
  </div>
</x-dashboard-layout>
