<x-auth-layout>
  <main class="flex min-h-screen items-center justify-center bg-atomic-cream px-6 py-12">
    <div class="w-full max-w-md rounded-2xl border-[3px] border-atomic-ink bg-white p-8 shadow-atomic">
      <a href="/" class="flex items-center gap-2">
        <span aria-hidden="true" class="font-display text-2xl text-atomic-coral">&#10022;</span>
        <span class="font-display text-2xl text-atomic-ink">{{ config('app.name') }}</span>
      </a>

      <h1 class="mt-8 font-display text-3xl tracking-tight text-atomic-ink">Two-factor check</h1>
      <p class="mt-3 text-atomic-ink/70">
        Type the code from your authenticator app. If you lost the device, type one of your recovery codes.
      </p>

      <x-auth-validation-errors class="mt-6" :errors="$errors" />

      <form method="POST" action="{{ route('two-factor.login') }}" class="mt-8 space-y-6">
        @csrf

        <div>
          <label for="code" class="block font-display text-sm uppercase tracking-wide text-atomic-ink">Authentication code</label>
          <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus
                 class="mt-2 block w-full rounded-lg border-[3px] border-atomic-ink bg-atomic-cream px-4 py-3 font-sans text-atomic-ink placeholder-atomic-ink/40 focus:outline-hidden focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral">
        </div>

        <div>
          <label for="recovery_code" class="block font-display text-sm uppercase tracking-wide text-atomic-ink">Recovery code</label>
          <input id="recovery_code" name="recovery_code" type="text" autocomplete="off"
                 class="mt-2 block w-full rounded-lg border-[3px] border-atomic-ink bg-atomic-cream px-4 py-3 font-sans text-atomic-ink placeholder-atomic-ink/40 focus:outline-hidden focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral">
          <p class="mt-2 text-sm text-atomic-ink/60">Leave this empty when you use the authentication code.</p>
        </div>

        <button type="submit"
                class="w-full rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-6 py-3 font-sans text-lg font-bold text-atomic-cream shadow-atomic transition hover:bg-atomic-coral-dk focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink">
          Verify and continue
        </button>
      </form>
    </div>
  </main>
</x-auth-layout>
