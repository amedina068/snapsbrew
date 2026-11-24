@php
  $inputClass = 'mt-2 block w-full rounded-lg border-[3px] border-atomic-ink bg-atomic-cream px-4 py-3 font-sans text-atomic-ink placeholder-atomic-ink/40 read-only:bg-atomic-chrome focus:outline-hidden focus:ring-0 focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral';
  $labelClass = 'block font-display text-sm uppercase tracking-wide text-atomic-ink';
  $hasInvitation = isset($invitation) && $invitation;
@endphp

<x-auth-layout>
  <x-auth.split
    kicker="open 24 hours"
    headline="A thousand on-brand assets in one click."
    :points="[
      'Design templates in the browser editor',
      'Render images and PDFs from the API',
      'Connect an AI assistant over MCP',
    ]"
    footer="rendered in seconds, every time"
  >
    <h1 class="mt-10 font-display text-4xl tracking-tight text-atomic-ink sm:text-5xl">
      Create your <span class="font-script font-normal text-atomic-coral">account</span>
    </h1>
    <p class="mt-3 text-lg text-atomic-ink/70">Create an account to design templates and render images.</p>

    <x-auth-session-status class="mt-6" :status="session('status')" />
    <x-auth-validation-errors class="mt-6" :errors="$errors" />

    @if ($hasInvitation)
      <div class="mt-6 rounded-xl border-[3px] border-atomic-ink bg-atomic-mint p-4 text-sm text-atomic-ink">
        You are joining <span class="font-bold">{{ $invitation->company->name }}</span>.
        Use the email <span class="font-bold">{{ $invitation->email }}</span> to accept this invitation.
      </div>
    @endif

    <form method="POST" action="/register" class="mt-8 space-y-6">
      @csrf
      @if ($hasInvitation)
        <input type="hidden" name="invitation_token" value="{{ $invitation->token }}">
      @endif

      <div>
        <label for="email" class="{{ $labelClass }}">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email', optional($invitation)->email) }}" @if ($hasInvitation) readonly @endif required autofocus class="{{ $inputClass }}">
      </div>

      <div>
        <label for="password" class="{{ $labelClass }}">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required class="{{ $inputClass }}">
      </div>

      <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3.5 font-sans text-lg font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink">
        Create free account <span aria-hidden="true">&rarr;</span>
      </button>
    </form>

    <p class="mt-6 text-sm text-atomic-ink/70">
      Already have an account?
      <a href="/login" class="font-bold text-atomic-coral-dk underline decoration-[3px] underline-offset-4 hover:text-atomic-coral">Log in</a>
    </p>
  </x-auth.split>
</x-auth-layout>
