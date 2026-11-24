@php
  $inputClass = 'mt-2 block w-full rounded-lg border-[3px] border-atomic-ink bg-atomic-cream px-4 py-3 font-sans text-atomic-ink placeholder-atomic-ink/40 focus:outline-hidden focus:ring-0 focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral';
  $labelClass = 'block font-display text-sm uppercase tracking-wide text-atomic-ink';
@endphp

<x-auth-layout>
  <x-auth.split
    kicker="grand opening"
    headline="Your own Snapsbrew kitchen."
    :points="[
      'Create the owner account',
      'Invite your team from the Account page',
      'Design a template and render your first image',
    ]"
    :home-link="false"
  >
    <h1 class="mt-10 font-display text-4xl tracking-tight text-atomic-ink sm:text-5xl">
      Set up your <span class="font-script font-normal text-atomic-coral">instance</span>
    </h1>
    <p class="mt-3 text-lg text-atomic-ink/70">
      Create the owner account. The owner manages this instance.
    </p>

    <x-auth-validation-errors class="mt-6" :errors="$errors" />

    <form method="POST" action="/setup" class="mt-8 space-y-6">
      @csrf

      <div>
        <label for="token" class="{{ $labelClass }}">Setup token</label>
        <input
          id="token"
          name="token"
          type="text"
          autocomplete="off"
          spellcheck="false"
          value="{{ old('token', $token) }}"
          required
          aria-describedby="token-help"
          class="{{ $inputClass }} font-mono"
        >
        <div id="token-help" class="mt-2 rounded-xl border-[3px] border-atomic-ink bg-atomic-mint p-4 text-sm text-atomic-ink">
          The setup token proves that you operate this server. The full setup URL contains the token. Find the URL in one of these places:
          <ul class="mt-2 list-inside list-disc">
            <li>The log of the web container: <code class="font-mono break-all">docker compose logs web</code></li>
            <li>The output of <code class="font-mono break-all">php artisan snapsbrew:setup-url</code></li>
          </ul>
        </div>
      </div>

      <div>
        <label for="name" class="{{ $labelClass }}">Your name</label>
        <input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" required autofocus class="{{ $inputClass }}">
      </div>

      <div>
        <label for="email" class="{{ $labelClass }}">Email address</label>
        <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required class="{{ $inputClass }}">
      </div>

      <div>
        <label for="password" class="{{ $labelClass }}">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required class="{{ $inputClass }}">
      </div>

      <div>
        <label for="password_confirmation" class="{{ $labelClass }}">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="{{ $inputClass }}">
      </div>

      <div>
        <label for="company" class="{{ $labelClass }}">Company name (optional)</label>
        <input id="company" name="company" type="text" autocomplete="organization" value="{{ old('company') }}" aria-describedby="company-help" class="{{ $inputClass }}">
        <p id="company-help" class="mt-2 text-sm text-atomic-ink/70">If you leave this empty, the company name is "Your Name's Company".</p>
      </div>

      <div class="flex items-start gap-3">
        <input type="hidden" name="starter_templates" value="0">
        <input
          id="starter_templates"
          name="starter_templates"
          type="checkbox"
          value="1"
          @checked(old('starter_templates', '1') === '1')
          aria-describedby="starter-templates-help"
          class="mt-1 h-5 w-5 shrink-0 rounded-sm border-[3px] border-atomic-ink text-atomic-coral focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-coral"
        >
        <div>
          <label for="starter_templates" class="{{ $labelClass }}">Add the starter templates</label>
          <p id="starter-templates-help" class="mt-1 text-sm text-atomic-ink/70">Six templates to start from: a link preview, a quote card, a certificate, a product launch, a blog header and a YouTube thumbnail.</p>
        </div>
      </div>

      <button
        type="submit"
        class="flex w-full items-center justify-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3.5 font-sans text-lg font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
      >
        Create the owner account <span aria-hidden="true">&rarr;</span>
      </button>
    </form>
  </x-auth.split>
</x-auth-layout>
