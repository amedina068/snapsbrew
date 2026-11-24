<x-dashboard-layout>
  <x-slot name="pageTitle">Admin</x-slot>

  @include('admin.partials.nav')

  @php
    $modes = [
        \App\Support\Registration::OPEN => [
            'label' => 'Open',
            'help' => 'Anyone can sign up. Each new account gets its own company.',
        ],
        \App\Support\Registration::INVITE_ONLY => [
            'label' => 'Invite only',
            'help' => 'Only people with an invitation from a company can sign up.',
        ],
    ];
  @endphp

  <form method="POST" action="/admin/settings" class="mt-8 rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
    @csrf
    @method('PUT')

    <fieldset>
      <legend class="text-lg font-semibold text-gray-900">Registration</legend>
      <p class="mt-1 text-sm text-gray-500">
        Who can create an account on this instance. When nobody sets a mode here, <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">REGISTRATION_ENABLED</code> gives it ({{ $modes[$defaultMode]['label'] }}).
      </p>

      <div class="mt-6 space-y-3">
        @foreach ($modes as $value => $mode)
          <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 transition hover:bg-gray-50 has-checked:border-rose-300 has-checked:bg-rose-50">
            <input
              type="radio"
              name="registration_mode"
              value="{{ $value }}" @checked($registrationMode === $value)
              class="mt-0.5 h-4 w-4 border-gray-300 text-rose-600 focus:ring-rose-500"
            >
            <span>
              <span class="block text-sm font-medium text-gray-900">{{ $mode['label'] }}</span>
              <span class="block text-sm text-gray-500">{{ $mode['help'] }}</span>
            </span>
          </label>
        @endforeach
      </div>

      @error('registration_mode')
        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
      @enderror
    </fieldset>

    <div class="mt-6 flex justify-end">
      <x-sb-primary-button type="submit">Save</x-sb-primary-button>
    </div>
  </form>
</x-dashboard-layout>
