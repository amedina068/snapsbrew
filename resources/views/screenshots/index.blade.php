<x-dashboard-layout>
  <x-slot name="pageTitle">Screenshots</x-slot>

  <x-slot name="pageActions">
    <x-ui.button icon="camera" href="/screenshots/new">New screenshot</x-ui.button>
  </x-slot>

  @if ($screenshots->isEmpty())
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
      <x-ui.action-card href="/documentation/screenshots" icon="book-open" title="Generate via API" cta="Read guide">
        Use our REST endpoint to automate captures from code.
      </x-ui.action-card>
      <x-ui.action-card href="/screenshots/new" icon="camera" title="Generate with form" cta="Open form">
        Fill out a quick form to create a screenshot on demand.
      </x-ui.action-card>
    </div>
  @else
    <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
      @foreach ($screenshots as $screenshot)
        <figure class="overflow-hidden rounded-xl border border-gray-200 bg-white">
          <a href="/screenshots/{{ $screenshot->uuid }}" class="focus-ring block">
            <img class="h-auto w-full object-cover" src="{{ $screenshot->imageUrl }}" alt="Screenshot of {{ $screenshot->url }}">
          </a>
          <figcaption class="truncate border-t border-gray-100 px-3 py-2 text-sm text-gray-600">
            <a href="{{ $screenshot->url }}" target="_blank" class="focus-ring rounded-sm hover:text-rose-700 hover:underline">{{ $screenshot->url }}</a>
          </figcaption>
        </figure>
      @endforeach
    </div>
  @endif
</x-dashboard-layout>
