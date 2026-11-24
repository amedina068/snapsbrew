@props(['for' => null])
<label @if ($for) for="{{ $for }}" @endif {{ $attributes->merge(['class' => 'block text-sm font-semibold text-gray-800']) }}>{{ $slot }}</label>
