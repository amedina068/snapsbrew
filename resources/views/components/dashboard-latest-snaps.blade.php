<section>
  <x-ui.section-heading title="Latest snaps" href="/snapshots" />
  <div class="mt-4 grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-4">
    @foreach ($snaps as $snap)
      <a href="/snapshots/{{ $snap->uuid }}" class="focus-ring block aspect-square overflow-hidden rounded-lg border border-gray-200 bg-slate-50">
        <img class="size-full object-contain" src="{{ $snap->imageUrl }}" alt="Snap {{ $snap->identifier ?? $snap->uuid }}">
      </a>
    @endforeach
  </div>
</section>
