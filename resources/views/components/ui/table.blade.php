{{--
  A data table in a white card. The card scrolls sideways on narrow screens.
  Put <x-ui.th> cells in the `head` slot and <tr> rows with <x-ui.td> in the
  default slot.
--}}
@props(['caption' => null])
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-gray-200 bg-white']) }}>
  <table class="w-full border-collapse text-left text-sm">
    @if ($caption)
      <caption class="sr-only">{{ $caption }}</caption>
    @endif
    <thead class="border-b border-gray-200 bg-slate-50 text-xs font-semibold text-gray-600">
      <tr>{{ $head }}</tr>
    </thead>
    <tbody class="[&>tr+tr]:border-t [&>tr+tr]:border-gray-100 [&>tr]:transition-colors [&>tr:hover]:bg-rose-50/40">
      {{ $slot }}
    </tbody>
  </table>
</div>
