{{-- Kept for App\View\Components\SbTable. New code uses <x-ui.table>. --}}
<x-ui.table>
  <x-slot name="head">{{ $head }}</x-slot>
  {{ $body }}
</x-ui.table>
