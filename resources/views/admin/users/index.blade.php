<x-dashboard-layout>
  <x-slot name="pageTitle">Admin</x-slot>

  @include('admin.partials.nav')

  <x-ui.panel class="mt-8" title="Users" description="Every account on this instance. A deactivated user keeps all data and cannot sign in or use a token.">
    <x-slot name="actions">
      <form method="GET" action="/admin/users" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center" role="search">
        <label for="user-search" class="sr-only">Search by name or email</label>
        <x-ui.input id="user-search" name="search" type="search" value="{{ $search }}" placeholder="Search by name or email" class="sm:w-72" />
        <x-ui.button variant="secondary">Search</x-ui.button>
        @if ($search !== '')
          <x-ui.button variant="ghost" href="/admin/users">Clear</x-ui.button>
        @endif
      </form>
    </x-slot>

    <x-ui.table caption="Users">
      <x-slot name="head">
        <x-ui.th>Name</x-ui.th>
        <x-ui.th>Email</x-ui.th>
        <x-ui.th>Company</x-ui.th>
        <x-ui.th>Created</x-ui.th>
        <x-ui.th>Status</x-ui.th>
        <x-ui.th align="right">Actions</x-ui.th>
      </x-slot>
      @forelse ($users as $member)
        <tr>
          <x-ui.td strong class="whitespace-nowrap">
            {{ $member->name ?: '—' }}
            @if ($member->isOwner())
              <x-ui.badge tone="brand" class="ml-2">Owner</x-ui.badge>
            @endif
            @if ($member->id === Auth::id())
              <x-ui.badge tone="info" class="ml-1">You</x-ui.badge>
            @endif
          </x-ui.td>
          <x-ui.td class="whitespace-nowrap">{{ $member->email }}</x-ui.td>
          <x-ui.td class="whitespace-nowrap">{{ $member->company?->name ?? '—' }}</x-ui.td>
          <x-ui.td class="whitespace-nowrap">
            <time datetime="{{ $member->created_at?->toIso8601String() }}">{{ $member->created_at?->format('M j, Y') }}</time>
          </x-ui.td>
          <x-ui.td>
            @if ($member->isDeactivated())
              <x-ui.badge title="Since {{ $member->deactivated_at->format('M j, Y H:i') }}">Deactivated</x-ui.badge>
            @else
              <x-ui.badge tone="success">Active</x-ui.badge>
            @endif
          </x-ui.td>
          <x-ui.td align="right">
            @if ($member->isOwner())
              <span class="text-gray-400">—</span>
            @else
              <div class="flex justify-end gap-2">
                @if ($member->isDeactivated())
                  <form method="POST" action="{{ url("/admin/users/{$member->id}/deactivation") }}"
                        onsubmit="return confirm(@js("Reactivate {$member->email}? This user can sign in and use tokens again."));">
                    @csrf
                    @method('DELETE')
                    <x-ui.button size="sm" variant="secondary">Reactivate</x-ui.button>
                  </form>
                @else
                  <form method="POST" action="{{ url("/admin/users/{$member->id}/deactivation") }}"
                        onsubmit="return confirm(@js("Deactivate {$member->email}? This user cannot sign in, and every token and signed URL of this user stops working. The data stays."));">
                    @csrf
                    <x-ui.button size="sm" variant="danger-ghost">Deactivate</x-ui.button>
                  </form>
                @endif
                <form method="POST" action="{{ url("/admin/users/{$member->id}") }}"
                      onsubmit="return confirm(@js("Delete {$member->email} and the data of this user? The tokens, keys, usage log and screenshots of this user are deleted. If this user is the last member of the company, the company and all its templates and snaps are deleted too. You cannot undo this."));">
                  @csrf
                  @method('DELETE')
                  <x-ui.button size="sm" variant="danger">Delete</x-ui.button>
                </form>
              </div>
            @endif
          </x-ui.td>
        </tr>
      @empty
        <tr>
          <x-ui.td colspan="6" class="py-8 text-center">
            @if ($search !== '')
              No user matches "{{ $search }}".
            @else
              No users yet.
            @endif
          </x-ui.td>
        </tr>
      @endforelse
    </x-ui.table>

    @if ($users->hasPages())
      <div class="mt-6">
        {{ $users->links() }}
      </div>
    @endif
  </x-ui.panel>
</x-dashboard-layout>
