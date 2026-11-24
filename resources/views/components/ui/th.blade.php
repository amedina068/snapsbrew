{{-- A header cell for <x-ui.table>. Use `hidden-label` for a column that needs no visible title. --}}
@props(['align' => 'left', 'hiddenLabel' => false])
<th scope="col" {{ $attributes->merge(['class' => 'px-4 py-2.5 whitespace-nowrap '.($align === 'right' ? 'text-right' : 'text-left')]) }}>
  @if ($hiddenLabel)
    <span class="sr-only">{{ $slot }}</span>
  @else
    {{ $slot }}
  @endif
</th>
