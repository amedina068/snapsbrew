{{--
  The create and edit form of an embedded form for one template.
  After a failed save, the fields show the submitted values and each error
  shows next to its field.
--}}
@php
    $selectedLayers = old('form_layers') !== null
        ? array_keys(old('form_layers'))
        : ($selectedLayers ?? []);
    $type = old('type', $form?->type ?? 'image');
@endphp
<form action="{{ $edit ? '/templates/'.$template->uuid.'/forms/'.$form->uuid : '/templates/'.$template->uuid.'/forms' }}" method="POST" class="mt-6 space-y-6 rounded-xl border border-gray-200 bg-white p-6">
    @csrf
    @if ($edit)
        @method('PATCH')
    @endif

    <div class="max-w-xl">
        <x-ui.label for="title">Title</x-ui.label>
        <x-ui.input id="title" name="title" required maxlength="255" class="mt-1"
            value="{{ old('title', $edit ? $form->title : '') }}"
            :aria-describedby="$errors->has('title') ? 'title-error' : null" />
        @error('title')
            <p id="title-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <fieldset @error('form_layers') aria-describedby="form-layers-error" @enderror>
        <legend class="text-sm font-semibold text-gray-800">Layers that the form can change</legend>
        @error('form_layers')
            <p id="form-layers-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
        <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($layers as $layer)
                <label for="layer_{{ $layer['key'] }}" class="inline-flex items-center gap-2">
                    <input type="checkbox" class="size-5 rounded-md" name="form_layers[{{ $layer['key'] }}]" id="layer_{{ $layer['key'] }}"
                        {{ in_array($layer['key'], $selectedLayers) ? 'checked' : '' }}>
                    <span class="break-all">
                        {{ $layer['key'] }}
                        <span class="text-sm font-semibold text-gray-500">({{ $layer['type'] ?? '—' }})</span>
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <fieldset>
        <legend class="text-sm font-semibold text-gray-800">Format</legend>
        <div class="mt-2 flex gap-4">
            <label class="inline-flex items-center gap-2" for="type_image">
                <input name="type" id="type_image" type="radio" value="image" {{ $type === 'image' ? 'checked' : '' }}>
                <span>Image</span>
            </label>
            <label class="inline-flex items-center gap-2" for="type_pdf">
                <input name="type" id="type_pdf" type="radio" value="pdf" {{ $type === 'pdf' ? 'checked' : '' }}>
                <span>PDF</span>
            </label>
        </div>
        @error('type')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </fieldset>

    <div class="flex flex-wrap gap-3">
        <x-ui.button>{{ $edit ? 'Save form' : 'Create form' }}</x-ui.button>
        <x-ui.button variant="secondary" href="{{ $edit ? '/templates/'.$template->uuid.'/forms/'.$form->uuid : '/templates/'.$template->uuid.'/forms' }}">Cancel</x-ui.button>
    </div>
</form>
