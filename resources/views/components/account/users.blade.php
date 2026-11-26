<x-ui.panel title="Team members" description="Manage who can access this workspace. Owners can change the company. Members share the templates and snaps.">
  <x-slot name="actions">
    @if ($canInvite)
      <form method="POST" action="/account/invitations" class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
        @csrf
        <label for="invite-email" class="sr-only">Email address</label>
        <x-ui.input id="invite-email" name="email" type="email" placeholder="teammate@example.com" value="{{ old('email') }}" required class="sm:w-72" />
        <x-ui.button>Invite user</x-ui.button>
      </form>
    @else
      <div class="flex items-center gap-3">
        <span id="invite-owner-only" class="text-sm text-gray-600">Only a company owner can do this.</span>
        <x-ui.button type="button" disabled aria-describedby="invite-owner-only">Invite user</x-ui.button>
      </div>
    @endif
  </x-slot>

  @error('email')
    <p class="mb-4 text-sm text-red-700">{{ $message }}</p>
  @enderror
  @error('role')
    <p class="mb-4 text-sm text-red-700" role="alert">{{ $message }}</p>
  @enderror
  @error('remove')
    <p class="mb-4 text-sm text-red-700" role="alert">{{ $message }}</p>
  @enderror

  <x-ui.table caption="Team members">
    <x-slot name="head">
      <x-ui.th>Name</x-ui.th>
      <x-ui.th>Email</x-ui.th>
      <x-ui.th>Role</x-ui.th>
      @if ($canManageMembers)
        <x-ui.th align="right">Actions</x-ui.th>
      @endif
    </x-slot>
    @forelse ($users as $member)
      @php($isOwner = $member->isCompanyOwner())
      <tr>
        <x-ui.td strong>
          {{ $member->name }}
          @if ($member->id === Auth::id())
            <x-ui.badge tone="info" class="ml-2">You</x-ui.badge>
          @endif
        </x-ui.td>
        <x-ui.td>{{ $member->email }}</x-ui.td>
        <x-ui.td>
          <x-ui.badge :tone="$isOwner ? 'brand' : 'neutral'">{{ $isOwner ? 'Owner' : 'Member' }}</x-ui.badge>
          @if ($member->isDeactivated())
            <x-ui.badge tone="warning" class="ml-2">Deactivated</x-ui.badge>
          @endif
        </x-ui.td>
        @if ($canManageMembers)
          <x-ui.td align="right">
            <div class="flex items-center justify-end gap-2">
            @if (! $isOwner)
              <form method="POST" action="/account/users/{{ $member->id }}/role"
                    onsubmit="return confirm(@js("Make {$member->email} an owner? An owner can change the company, its invitations, its fonts and the roles of all users."))">
                @method('PATCH')
                @csrf
                <input type="hidden" name="role" value="owner">
                <x-ui.button size="sm" variant="ghost" aria-label="Make {{ $member->email }} an owner">Make owner</x-ui.button>
              </form>
            @elseif ($canBecomeMember($member))
              <form method="POST" action="/account/users/{{ $member->id }}/role"
                    onsubmit="return confirm(@js("Make {$member->email} a member? A member cannot change the company."))">
                @method('PATCH')
                @csrf
                <input type="hidden" name="role" value="member">
                <x-ui.button size="sm" variant="ghost" aria-label="Make {{ $member->email }} a member">Make member</x-ui.button>
              </form>
            @else
              <span class="text-xs text-gray-500">The last active owner stays an owner.</span>
            @endif
            @if ($canRemove($member))
              <form method="POST" action="/account/users/{{ $member->id }}"
                    onsubmit="return confirm(@js($member->id === Auth::id()
                      ? 'Remove yourself from the company? You cannot sign in after this, and every token and signed URL of your account stops working. Your templates and snaps stay in the company.'
                      : "Remove {$member->email} from the company? This user cannot sign in after this, and every token and signed URL of this user stops working. The templates and snaps of this user stay in the company."))">
                @method('DELETE')
                @csrf
                <x-ui.button size="sm" variant="danger-ghost" aria-label="Remove {{ $member->email }} from the company">Remove</x-ui.button>
              </form>
            @endif
            </div>
          </x-ui.td>
        @endif
      </tr>
    @empty
      <tr><x-ui.td colspan="{{ $canManageMembers ? 4 : 3 }}" class="py-8 text-center">No team members added yet.</x-ui.td></tr>
    @endforelse
  </x-ui.table>

  @if ($invitations->isNotEmpty())
    <h3 class="mt-8 mb-3 text-sm font-semibold text-gray-900">Pending invitations</h3>
    @if ($canInvite && ! \App\Support\MailDelivery::isConfigured())
      <p class="mb-3 rounded-lg border-l-4 border-l-amber-400 bg-amber-50 px-4 py-3 text-sm text-gray-800">
        Mail is not set up. Send this link yourself. Click <strong>Copy invite link</strong> and paste the link in a message to the person.
      </p>
    @endif
    <x-ui.table caption="Pending invitations">
      <x-slot name="head">
        <x-ui.th>Email</x-ui.th>
        <x-ui.th>Invited by</x-ui.th>
        <x-ui.th>Expires</x-ui.th>
        @if ($canInvite)
          <x-ui.th align="right">Actions</x-ui.th>
        @endif
      </x-slot>
      @foreach ($invitations as $invitation)
        <tr>
          <x-ui.td strong>{{ $invitation->email }}</x-ui.td>
          <x-ui.td>{{ optional($invitation->inviter)->name ?? optional($invitation->inviter)->email ?? 'Unknown' }}</x-ui.td>
          <x-ui.td>{{ optional($invitation->expires_at)->diffForHumans() ?? '—' }}</x-ui.td>
          @if ($canInvite)
          <x-ui.td align="right">
            <div class="flex items-center justify-end gap-2">
            <x-ui.button
              type="button"
              size="sm"
              variant="ghost"
              icon="clipboard-document"
              data-copy-text="{{ $invitation->url() }}"
              aria-label="Copy the invite link of {{ $invitation->email }}"
            ><span data-copy-label>Copy invite link</span></x-ui.button>
            <form method="POST" action="/account/invitations/{{ $invitation->id }}"
                  onsubmit="return confirm(@js("Cancel the invitation of {$invitation->email}? The link in the invitation email stops working."))">
              @method('DELETE')
              @csrf
              <x-ui.button size="sm" variant="danger-ghost" aria-label="Cancel the invitation of {{ $invitation->email }}">Cancel invitation</x-ui.button>
            </form>
            </div>
          </x-ui.td>
          @endif
        </tr>
      @endforeach
    </x-ui.table>
  @endif
</x-ui.panel>
