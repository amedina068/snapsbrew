{{-- A body cell for <x-ui.table>. `strong` marks the cell that names the row. --}}
@props(['align' => 'left', 'strong' => false])
<td {{ $attributes->merge(['class' => 'px-4 py-3 align-middle '.($align === 'right' ? 'text-right ' : '').($strong ? 'font-medium text-gray-900' : 'text-gray-600')]) }}>{{ $slot }}</td>
