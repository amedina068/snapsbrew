<x-auth-layout>
  <div class="flex min-h-screen items-center justify-center bg-atomic-cream px-4 py-12 sm:px-6">
    <div class="w-full max-w-xl">

      <a href="/" class="flex items-center justify-center gap-2">
        <span aria-hidden="true" class="font-display text-3xl text-atomic-coral">&#10022;</span>
        <span class="font-display text-3xl text-atomic-ink">{{ config('app.name') }}</span>
      </a>

      <div class="mt-8 rounded-2xl border-[3px] border-atomic-ink bg-white shadow-atomic-lg">

        <div class="border-b-[3px] border-atomic-ink px-6 py-7 sm:px-10">
          <h1 class="font-display text-3xl tracking-tight text-atomic-ink sm:text-4xl">
            Connect <span class="font-script font-normal text-atomic-coral">{{ $client->name }}</span>
          </h1>
          <p class="mt-3 text-base text-atomic-ink/70">
            This application asks to use your {{ config('app.name') }} account.
          </p>
        </div>

        {{--
          The registration endpoint is open, as the MCP specification requires.
          Anyone can register a client and choose the name above. The person on
          this screen is the only check on who gets a token, so the screen says
          that plainly instead of showing a name that looks approved.
        --}}
        <div class="border-b-[3px] border-atomic-ink bg-atomic-mustard px-6 py-5 sm:px-10">
          <p class="font-sans text-sm font-semibold leading-6 text-atomic-ink">
            {{ config('app.name') }} did not review this application. It chose its own name.
            Approve it only if you started this connection yourself.
          </p>
        </div>

        <div class="px-6 py-7 sm:px-10">
          <h2 class="font-display text-sm uppercase tracking-wide text-atomic-ink">
            What it will be able to do
          </h2>

          <ul class="mt-4 space-y-3">
            @forelse ($scopes as $scope)
              <li class="flex gap-3">
                <span aria-hidden="true" class="font-display text-lg leading-6 text-atomic-aqua-dk">&#10022;</span>
                <span class="text-base leading-6 text-atomic-ink">{{ $scope->description }}</span>
              </li>
            @empty
              <li class="flex gap-3">
                <span aria-hidden="true" class="font-display text-lg leading-6 text-atomic-aqua-dk">&#10022;</span>
                <span class="text-base leading-6 text-atomic-ink">Read your account details.</span>
              </li>
            @endforelse
          </ul>

          <p class="mt-5 text-sm leading-6 text-atomic-ink/70">
            Every snap it renders counts in the usage log of this account. You
            can end this connection at any time from your account page.
          </p>

          <div class="mt-7 rounded-2xl border-[3px] border-atomic-ink bg-atomic-chrome px-5 py-4">
            <p class="font-display text-xs uppercase tracking-wide text-atomic-ink">Connecting as</p>
            <p class="mt-1 font-sans text-base font-semibold text-atomic-ink">{{ $user->name }}</p>
            <p class="text-sm text-atomic-ink/70">{{ $user->email }}</p>
          </div>

          <div class="mt-8 flex flex-col gap-3 sm:flex-row-reverse">
            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="sm:flex-1">
              @csrf
              <input type="hidden" name="auth_token" value="{{ $authToken }}">
              <button
                type="submit"
                class="flex w-full items-center justify-center gap-2 rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-7 py-3.5 font-sans text-lg font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
              >
                Connect <span aria-hidden="true">&rarr;</span>
              </button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="sm:flex-1">
              @csrf
              @method('DELETE')
              <input type="hidden" name="auth_token" value="{{ $authToken }}">
              <button
                type="submit"
                class="flex w-full items-center justify-center rounded-full border-[3px] border-atomic-ink bg-atomic-cream px-7 py-3.5 font-sans text-lg font-bold text-atomic-ink shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-chrome hover:shadow-[3px_3px_0_0_#211d1a] focus-visible:outline-solid focus-visible:outline-[3px] focus-visible:outline-offset-2 focus-visible:outline-atomic-ink"
              >
                Cancel
              </button>
            </form>
          </div>
        </div>
      </div>

    </div>
  </div>
</x-auth-layout>
