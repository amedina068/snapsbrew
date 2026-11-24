<div class="space-y-10">
  <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="text-lg font-semibold text-gray-900">Two-factor authentication</h2>
        <p class="mt-1 text-sm text-gray-500">Add an extra layer of protection to your {{ config('app.name') }} account.</p>
      </div>
      <div class="flex flex-wrap gap-3">
        @if (! $twoFactorEnabled)
          <form method="POST" action="{{ route('two-factor.enable', ['tab' => 'security']) }}">
            @csrf
            <x-ui.button>Enable 2FA</x-ui.button>
          </form>
        @else
          <form method="POST" action="{{ route('two-factor.disable', ['tab' => 'security']) }}"
                onsubmit="return confirm('Disable two-factor authentication? Your account then needs only your password to sign in.')">
            @method('DELETE')
            @csrf
            <x-ui.button variant="danger">Disable 2FA</x-ui.button>
          </form>
        @endif
      </div>
    </div>

    @if ($twoFactorEnabled)
      <div class="mt-6 space-y-6">

        <div class="grid gap-6 lg:grid-cols-2">
          <div>
            <h3 class="text-sm font-semibold text-gray-700">Scan the QR code</h3>
            <p class="mt-1 text-sm text-gray-500">Add {{ config('app.name') }} to Google Authenticator or another TOTP-compatible app.</p>
            <div class="mt-4 inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white p-6">
              {!! $user->twoFactorQrCodeSvg() !!}
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-sm font-semibold text-gray-700">Recovery codes</h3>
                <p class="mt-1 text-sm text-gray-500">Store these codes in a safe place in case you lose your device.</p>
              </div>
              <form method="POST" action="{{ route('two-factor.recovery-codes', ['tab' => 'security']) }}"
                    onsubmit="return confirm('Make new recovery codes? The codes that you saved before stop working.')">
                @csrf
                <x-ui.button size="sm" variant="secondary">Make new codes</x-ui.button>
              </form>
            </div>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
              @foreach ($recoveryCodes as $code)
                <div class="rounded-md bg-gray-50 px-3 py-2 font-mono text-sm tracking-widest text-gray-700">{{ $code }}</div>
              @endforeach
            </div>
          </div>
        </div>

        @if (! $twoFactorConfirmed)
          <form method="POST" action="{{ route('two-factor.confirm', ['tab' => 'security']) }}" class="space-y-3 max-w-md">
            @csrf
            <label for="confirmation-code" class="block text-sm font-medium text-gray-700">Enter the 6-digit code from your authenticator app</label>
            <input
              id="confirmation-code"
              name="code"
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:border-gray-500 focus:ring-0"
            >
            @error('code')
              <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <div>
              <x-ui.button>Confirm setup</x-ui.button>
            </div>
          </form>
        @endif
      </div>
    @else
      <p class="mt-6 text-sm text-gray-500">
        Enabling two-factor authentication will require a code from your authenticator app whenever you sign in.
      </p>
    @endif
  </div>

  <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
    <h2 class="text-lg font-semibold text-gray-900">Update password</h2>
    <p class="mt-1 text-sm text-gray-500">Choose a unique password to keep your account secure.</p>

    <form method="POST" action="{{ route('user-password.update', ['tab' => 'security']) }}" class="mt-6 space-y-4">
      @method('PUT')
      @csrf
      <div>
        <label for="current_password" class="block text-sm font-medium text-gray-700">Current password</label>
        <input
          id="current_password"
          name="current_password"
          type="password"
          autocomplete="current-password"
          required
          class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:border-gray-500 focus:ring-0"
        >
        @error('current_password', 'updatePassword')
          <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="new_password" class="block text-sm font-medium text-gray-700">New password</label>
        <input
          id="new_password"
          name="password"
          type="password"
          autocomplete="new-password"
          required
          class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:border-gray-500 focus:ring-0"
        >
        @error('password', 'updatePassword')
          <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
      </div>

      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm new password</label>
        <input
          id="password_confirmation"
          name="password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:border-gray-500 focus:ring-0"
        >
      </div>

      <div class="flex justify-end">
        <x-ui.button>Update password</x-ui.button>
      </div>
    </form>
  </div>
</div>
