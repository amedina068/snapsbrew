<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div class="space-y-3">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-lg tracking-wide text-gray-900">Generate a snap</h1>
    </div>
  </x-slot>

  <div class="mt-6">
    <snap-generator
      store-url="{{ url('templates/'.$template->uuid.'/generate') }}"
      preview-image="{{ $template->previewImage }}"
      :fields="{{ json_encode($textLayers) }}"
      :first-snap="{{ $firstSnap ? 'true' : 'false' }}"
    ></snap-generator>
  </div>
</x-dashboard-layout>
