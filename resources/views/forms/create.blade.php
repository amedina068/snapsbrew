<x-dashboard-layout>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
          ['label' => 'Forms', 'href' => url('templates/'.$template->uuid.'/forms')],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-lg tracking-wide text-gray-900">New form for {{ $template->title }}</h1>
    </div>
  </x-slot>

  <p class="max-w-2xl text-lg text-gray-700">Choose the layers that people can change in the form. The title helps you find the form later.</p>

  <div class="mt-8 max-w-5xl">
    <x-forms-form :template="$template" :layers="$layers" />
  </div>

</x-dashboard-layout>
