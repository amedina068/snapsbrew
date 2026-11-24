@php
  $links = [
      ['href' => '/dashboard', 'label' => 'Dashboard', 'active' => request()->is('dashboard*')],
      ['href' => '/templates', 'label' => 'Templates', 'active' => request()->is('templates*')],
      ['href' => '/collections', 'label' => 'Collections', 'active' => request()->is('collections*')],
      ['href' => '/snapshots', 'label' => 'Snaps', 'active' => request()->is('snapshots*')],
      ['href' => '/screenshots', 'label' => 'Screenshots', 'active' => request()->is('screenshots*')],
      ['href' => '/fonts', 'label' => 'Fonts', 'active' => request()->is('fonts*')],
  ];
  // Only the owner of the instance sees the admin area.
  if (auth()->user()?->isOwner()) {
      $links[] = ['href' => '/admin/users', 'label' => 'Admin', 'active' => request()->is('admin*')];
  }
  $mobileLinks = array_merge($links, [['href' => '/documentation', 'label' => 'Documentation', 'active' => false]]);
@endphp
<header class="border-t-4 border-rose-500 bg-white shadow-xs">
  <div class="flex h-16 items-center gap-6 px-4 sm:px-6 lg:px-8">
    <a v-pre href="/dashboard" class="max-w-xs truncate whitespace-nowrap text-2xl font-semibold tracking-wide text-rose-600">{{ config('app.name') }}</a>

    <nav class="hidden flex-1 items-stretch gap-6 self-stretch sm:flex" aria-label="Main">
      @foreach ($links as $link)
        <x-dashboard-topbar-item :href="$link['href']" :is-active="$link['active']">{{ $link['label'] }}</x-dashboard-topbar-item>
      @endforeach
      <x-dashboard-topbar-item href="/documentation" target="_blank">Documentation</x-dashboard-topbar-item>
    </nav>

    <div class="ml-auto hidden sm:block">
      <sc-dropdown-menu :items="{{ $profileItems }}" logout-form="logout-form">
        <template v-slot:trigger>
          <span v-pre class="flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-100">
            {{ auth()->user()?->name ?? auth()->user()?->email }}
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M5 7l5 6 5-6H5z" /></svg>
          </span>
        </template>
      </sc-dropdown-menu>
    </div>

    <button type="button" onclick="window.emitter.emit('toggleDashboardMenu')"
            class="ml-auto rounded-md p-2 text-gray-500 hover:bg-gray-100 sm:hidden" aria-controls="dashboard-menu" aria-expanded="false">
      <span class="sr-only">Menu</span>
      <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" /></svg>
    </button>
  </div>

  <dashboard-mobile-menu :links='@json($mobileLinks)' :profile-items="{{ $profileItems }}"></dashboard-mobile-menu>

  <form id="logout-form" action="/logout" method="POST" class="hidden">
    @csrf
  </form>
</header>
