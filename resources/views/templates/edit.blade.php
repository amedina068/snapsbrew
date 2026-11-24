<x-dashboard-layout>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
          ['label' => 'Edit', 'current' => true],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-lg tracking-wide text-gray-900">Edit template</h1>
    </div>
  </x-slot>

  <div class="mt-6 max-w-5xl mx-auto">
    <form action="/templates/{{$template->uuid}}" method="POST" class="rounded-xl border border-gray-200 bg-white p-5">
      @csrf
      @method('PATCH')
      @php $selectedSize = old('size', $selectedDefaultSize['value'] ?? 'custom'); @endphp
      <div class="max-w-xl">
        <x-ui.label for="title">Title</x-ui.label>
        <x-ui.input value="{{ old('title', $template->title) }}" id="title" name="title" required maxlength="255" class="mt-1" :aria-describedby="$errors->has('title') ? 'title-error' : null" />
        @error('title')
          <p id="title-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
      </div>

      <div class="mt-4 max-w-xs">
        <x-ui.label for="size">Size</x-ui.label>
        <x-ui.select id="size" name="size" class="mt-1" onchange="window.emitter.emit('sizeChanged', this.value)">
          @foreach($defaultSizes as $platform => $platformSizes)
            <optgroup label="{{ $platform }}">
              @foreach($platformSizes as $defaultSize)
                <option value="{{ $defaultSize['value'] }}" {{ $selectedSize == $defaultSize['value'] ? 'selected' : '' }}>{{ $defaultSize['name'] }}</option>
              @endforeach
            </optgroup>
          @endforeach
          <option value="custom" {{ $selectedSize == 'custom' ? 'selected' : '' }}>Custom</option>
        </x-ui.select>
        @error('size')
          <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
      </div>

      <div id="custom_sizes" class="{{ $selectedSize == 'custom' ? 'block' : 'hidden' }}">
        <div class="mt-4 max-w-xs">
          <x-ui.label for="width">Width in pixels</x-ui.label>
          <x-ui.input value="{{ old('width', $template->width) }}" id="width" name="width" type="number" min="1" max="{{ \App\Models\Template::MAX_DIMENSION }}" class="mt-1" :aria-describedby="$errors->has('width') ? 'width-error' : null" />
          @error('width')
            <p id="width-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
          @enderror
        </div>

        <div class="mt-4 max-w-xs">
          <x-ui.label for="height">Height in pixels</x-ui.label>
          <x-ui.input value="{{ old('height', $template->height) }}" id="height" name="height" type="number" min="1" max="{{ \App\Models\Template::MAX_DIMENSION }}" class="mt-1" :aria-describedby="$errors->has('height') ? 'height-error' : null" />
          @error('height')
            <p id="height-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
          @enderror
        </div>
      </div>

      <div class="mt-6 flex flex-wrap gap-3">
        <x-ui.button>Save changes</x-ui.button>
        <x-ui.button variant="secondary" href="/templates/{{ $template->uuid }}">Cancel</x-ui.button>
      </div>
    </form>
  </div>

<x-slot name="scripts">
  <script>
    window.addEventListener("load", function () {
      window.emitter.on('sizeChanged', function (value) {
        value == 'custom'
          ? document.getElementById('custom_sizes').style.display = 'block'
          : document.getElementById('custom_sizes').style.display = 'none'
      })
    })
  </script>
</x-slot>

</x-dashboard-layout>
