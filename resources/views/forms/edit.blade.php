<x-dashboard-layout>
  <x-slot name="pageTitle">
    Edit form {{ $form->title }}
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Change the title, the format or the layers that the form can change. The snaps that the form made before do not change.</p>

  <div class="mt-8 max-w-5xl">
    <x-forms-form edit :template="$template" :form="$form" :layers="$layers" :selected-layers="$selectedLayers" />
  </div>

</x-dashboard-layout>
