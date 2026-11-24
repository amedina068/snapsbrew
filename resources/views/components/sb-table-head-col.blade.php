{{-- Kept for App\View\Components\SbTableHeadCol. New code uses <x-ui.th>. --}}
<x-ui.th :align="$right ? 'right' : 'left'" :hidden-label="(bool) $muted" {{ $attributes }}>{{ $slot }}</x-ui.th>
