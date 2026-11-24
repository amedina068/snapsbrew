<x-ui.panel title="Team members" description="Manage who can access this workspace.">
  <x-slot name="actions">
    <form method="POST" action="/account/invitations" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
      @csrf
      <label for="invite-email" class="sr-only">Email address</label>
      <x-ui.input id="invite-email" name="email" type="email" placeholder="teammate@example.com" value="{{ old('email') }}" required class="sm:w-72" />
      <x-ui.button>Invite user</x-ui.button>
    </form>
  </x-slot>

  @error('email')
    <p class="mb-4 text-sm text-red-700">{{ $message }}</p>
  @enderror

  <x-ui.table caption="Team members">
    <x-slot name="head">
      <x-ui.th>Name</x-ui.th>
      <x-ui.th>Email</x-ui.th>
    </x-slot>
    @forelse ($users as $member)
      <tr>
        <x-ui.td strong>
          {{ $member->name }}
          @if ($member->id === Auth::id())
            <x-ui.badge tone="info" class="ml-2">You</x-ui.badge>
          @endif
        </x-ui.td>
        <x-ui.td>{{ $member->email }}</x-ui.td>
      </tr>
    @empty
      <tr><x-ui.td colspan="2" class="py-8 text-center">No team members added yet.</x-ui.td></tr>
    @endforelse
  </x-ui.table>

  @if ($invitations->isNotEmpty())
    <h3 class="mt-8 mb-3 text-sm font-semibold text-gray-900">Pending invitations</h3>
    <x-ui.table caption="Pending invitations">
      <x-slot name="head">
        <x-ui.th>Email</x-ui.th>
        <x-ui.th>Invited by</x-ui.th>
        <x-ui.th>Expires</x-ui.th>
        <x-ui.th align="right">Actions</x-ui.th>
      </x-slot>
      @foreach ($invitations as $invitation)
        <tr>
          <x-ui.td strong>{{ $invitation->email }}</x-ui.td>
          <x-ui.td>{{ optional($invitation->inviter)->name ?? optional($invitation->inviter)->email ?? 'Unknown' }}</x-ui.td>
          <x-ui.td>{{ optional($invitation->expires_at)->diffForHumans() ?? '—' }}</x-ui.td>
          <x-ui.td align="right">
            <form method="POST" action="/account/invitations/{{ $invitation->id }}"
                  onsubmit="return confirm(@js("Cancel the invitation of {$invitation->email}? The link in the invitation email stops working."))">
              @method('DELETE')
              @csrf
              <x-ui.button size="sm" variant="danger-ghost" aria-label="Cancel the invitation of {{ $invitation->email }}">Cancel invitation</x-ui.button>
            </form>
          </x-ui.td>
        </tr>
      @endforeach
    </x-ui.table>
  @endif
</x-ui.panel>
