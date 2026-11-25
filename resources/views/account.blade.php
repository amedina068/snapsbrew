{{-- The account tabs are a Vue component, so each tab puts v-pre on its own user text. --}}
<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">Account</x-slot>

  <account-tabs initial-tab="{{ $initialTab }}">
    <template #alerts>
      {{-- The token holds only an id, "|" and letters and digits, so the copy field can sit outside v-pre. --}}
      @if(session('token'))
        <div class="mb-8 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
          <p class="font-semibold">New API token created</p>
          <x-ui.copy-field class="mt-2 bg-white" :value="session('token')" />
          <p class="mt-3 text-xs">Copy this token now. You cannot see it again.</p>
        </div>
      @endif

      @if ($statusMessage ?? false)
        <div v-pre class="mb-8 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
          {{ $statusMessage }}
        </div>
      @endif

      {{-- The invite link holds APP_URL and a token of letters and digits, so the copy field can sit outside v-pre. --}}
      @if (session('invite_url'))
        <div class="mb-8 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
          <p class="font-semibold">Invite link</p>
          <x-ui.copy-field class="mt-2 bg-white" :value="session('invite_url')" />
          <p class="mt-3 text-xs">The link works for 7 days. The person who opens it can sign up and join your company.</p>
        </div>
      @endif
    </template>

    <template #header>
      <div v-pre class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-2xl font-semibold text-gray-900">{{ Auth::user()->name }}</p>
          <p class="text-sm text-gray-500">{{ Auth::user()->email }}</p>
        </div>
      </div>
    </template>

    <template #company>
      <div v-pre>
        <x-account.company-info :company="$company" :company-renders-last7-days="$companyRendersLast7Days" />
      </div>
    </template>

    <template #users>
      <div v-pre>
        <x-account.users :users="$users" :invitations="$invitations" />
      </div>
    </template>

    <template #developers>
      <x-account.developers :tokens="$tokens" :api-keys="$apiKeys" :oauth-connections="$oauthConnections" :webhook-secret="$company->webhook_secret" />
    </template>

    <template #security>
      <div v-pre>
        <x-account.security :user="$user" />
      </div>
    </template>
  </account-tabs>
</x-dashboard-layout>
