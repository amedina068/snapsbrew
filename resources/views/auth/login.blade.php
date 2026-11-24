@php
  $inputClass = 'mt-2 block w-full rounded-lg border-[3px] border-atomic-ink bg-atomic-cream px-4 py-3 font-sans text-atomic-ink placeholder-atomic-ink/40 focus:outline-hidden focus:ring-0 focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral';
  $labelClass = 'block font-display text-sm uppercase tracking-wide text-atomic-ink';
@endphp

<x-auth-layout>
  <x-auth.split
    kicker="open 24 hours"
    headline="Your marketing kitchen never closes."
    :points="[
      'Every template and collection right where you left them',
      'Async jobs keep rendering while you are away',
      'Webhooks and imports running around the clock',
    ]"
    footer="rendered in seconds, every time"
  >
    <h1 class="mt-10 font-display text-4xl tracking-tight text-atomic-ink sm:text-5xl">
      Welcome <span class="font-script font-normal text-atomic-coral">back</span>
    </h1>
    <p class="mt-3 text-lg text-atomic-ink/70">Sign in to pick up where you left off.</p>

    <x-auth-session-status class="mt-6" :status="session('status')" />
    <x-auth-validation-errors class="mt-6" :errors="$errors" />

    <form method="POST" action="/login" class="mt-8 space-y-6">
      @csrf

      <div>
        <label for="email" class="{{ $labelClass }}">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus class="{{ $inputClass }}">
      </div>

      <div>
        <label for="password" class="{{ $labelClass }}">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required class="{{ $inputClass }}">
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <label for="remember_me" class="flex items-center gap-2 text-sm text-atomic-ink">
          <input id="remember_me" name="remember" type="checkbox" class="size-5 rounded-sm border-[3px] border-atomic-ink text-atomic-coral focus:ring-0 focus:ring-offset-0 focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink">
          Remember me
        </label>

        @if (Route::has('password.request'))
          <a href="/forgot-password" class="text-sm font-bold text-atomic-coral-dk underline decoration-[3px] underline-offset-4 hover:text-atomic-coral">{{ __('Forgot your password?') }}</a>
        @endif
      </div>

      <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3.5 font-sans text-lg font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink">
        Sign in <span aria-hidden="true">&rarr;</span>
      </button>
    </form>

    @if (\App\Support\Registration::isOpen())
      <p class="mt-6 text-sm text-atomic-ink/70">
        New here?
        <a href="/register" class="font-bold text-atomic-coral-dk underline decoration-[3px] underline-offset-4 hover:text-atomic-coral">Create an account</a>
      </p>
    @endif
  </x-auth.split>
</x-auth-layout>
