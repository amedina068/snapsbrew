{{--
  One snap as a square tile that opens the snap page. A snap that has no
  image yet (pending, processing or failed) shows its status, not an empty image.
--}}
@php
    $snapName = $snap->identifier ?? 'Snap #'.$snap->id;
    $imageUrl = $snap->isComplete() ? $snap->imageUrl : null;
@endphp
<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-1 shadow-xs']) }}>
  <a href="/snapshots/{{ $snap->uuid }}" class="focus-ring block aspect-square overflow-hidden rounded-md border border-gray-200 bg-gray-50">
    @if ($imageUrl)
      <img class="size-full object-contain" src="{{ $imageUrl }}" alt="Snap {{ $snapName }}">
    @else
      <span class="flex size-full flex-col items-center justify-center gap-2 p-4 text-center">
        <x-ui.status-badge :status="$snap->status" />
        <span class="text-xs text-gray-600">
          <span class="sr-only">Snap {{ $snapName }}: </span>{{ $snap->status === \App\Models\Snap::STATUS_FAILED ? 'This snap did not render.' : 'The image is not ready yet.' }}
        </span>
      </span>
    @endif
  </a>
</div>
