{{-- A text field. All attributes pass through to the <input>. --}}
<input {{ $attributes->merge(['type' => 'text', 'class' => 'focus-ring block min-h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-500 focus:ring-0 disabled:bg-slate-100 read-only:bg-slate-50']) }}>
