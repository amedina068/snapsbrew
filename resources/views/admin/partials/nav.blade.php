@php
  $tabs = [
      ['href' => '/admin/users', 'label' => 'Users', 'active' => request()->is('admin/users*')],
      ['href' => '/admin/settings', 'label' => 'Settings', 'active' => request()->is('admin/settings*')],
  ];
@endphp
<nav aria-label="Admin">
  <ul class="inline-flex gap-1 rounded-full border border-gray-200 bg-white p-1">
    @foreach ($tabs as $tab)
      <li>
        <a href="{{ $tab['href'] }}"
           @if ($tab['active']) aria-current="page" @endif
           class="{{ $tab['active'] ? 'bg-rose-600 text-white' : 'text-gray-600 hover:bg-slate-100 hover:text-gray-900' }} focus-ring block rounded-full px-4 py-1.5 text-sm font-semibold transition-colors">
          {{ $tab['label'] }}
        </a>
      </li>
    @endforeach
  </ul>
</nav>

@if (session('status'))
  <div class="mt-6 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
    {{ session('status') }}
  </div>
@endif
