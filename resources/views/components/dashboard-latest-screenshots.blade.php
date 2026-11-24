<section>
  <x-ui.section-heading title="Latest Screenshots" href="/screenshots" />
  <div class="mt-4 grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
    @foreach ($screenshots as $screenshot)
      <a href="/screenshots/{{ $screenshot->uuid }}" class="focus-ring block overflow-hidden rounded-lg border border-gray-200 bg-slate-50">
        <img class="h-auto w-full object-cover" src="{{ $screenshot->imageUrl }}" alt="Screenshot of {{ $screenshot->url }}">
      </a>
    @endforeach
  </div>
</section>
