{{-- Kept for App\View\Components\SbTableBodyCol. New code uses <x-ui.td>. --}}
<x-ui.td :align="$right ? 'right' : 'left'" :strong="(bool) $initial" {{ $attributes }}>{{ $slot }}</x-ui.td>
