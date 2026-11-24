<x-dashboard-layout>
  <x-slot name="pageTitle">New Screenshot</x-slot>

  <form action="/screenshots" method="POST" class="mx-auto mt-6 max-w-lg space-y-4 rounded-xl border border-gray-200 bg-white p-5">
    @csrf
    <div>
      <x-ui.label for="url">URL</x-ui.label>
      <x-ui.input name="url" id="url" placeholder="https://example.com" value="{{ old('url') }}" required class="mt-1" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <x-ui.label for="width">Width</x-ui.label>
        <x-ui.input name="width" id="width" type="number" placeholder="1330" value="{{ old('width') }}" required class="mt-1" />
      </div>
      <div>
        <x-ui.label for="height">Height</x-ui.label>
        <x-ui.input name="height" id="height" type="number" placeholder="780" value="{{ old('height') }}" required class="mt-1" />
      </div>
    </div>

    <div class="flex justify-end">
      <x-ui.button icon="camera" class="w-full sm:w-auto">Submit</x-ui.button>
    </div>
  </form>
</x-dashboard-layout>
