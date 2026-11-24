{{-- A native select. All attributes pass through to the <select>. --}}
<select {{ $attributes->merge(['class' => 'focus-ring block min-h-10 w-full rounded-lg border border-gray-300 bg-white py-2 pr-9 pl-3 text-sm text-gray-900 focus:border-gray-500 focus:ring-0']) }}>
  {{ $slot }}
</select>
