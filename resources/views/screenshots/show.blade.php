<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">Screenshot</x-slot>

  <x-ui.panel class="mt-6">
    <x-ui.copy-field :value="$screenshot->imageUrl" />
    <img class="mt-6 w-full rounded-lg border border-gray-200" src="{{ $screenshot->imageUrl }}" alt="Screenshot">
  </x-ui.panel>
</x-dashboard-layout>
