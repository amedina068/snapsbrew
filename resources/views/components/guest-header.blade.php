<header class="border-b-[3px] border-atomic-ink bg-atomic-cream">
  <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-4 lg:px-8" aria-label="Main">
    <a href="/" class="flex items-center gap-2">
      <span aria-hidden="true" class="font-display text-2xl text-atomic-coral">&#10022;</span>
      <span class="font-display text-2xl text-atomic-ink">{{ config('app.name') }}</span>
    </a>

    <div class="flex items-center gap-5">
      <a href="/documentation" class="font-sans text-sm font-bold uppercase tracking-wide text-atomic-ink hover:text-atomic-coral">Docs</a>
      @auth
        <a href="/dashboard" class="rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-5 py-2 font-sans text-sm font-bold text-atomic-cream shadow-atomic hover:bg-atomic-coral-dk">Dashboard</a>
      @else
        <a href="/login" class="font-sans text-sm font-bold uppercase tracking-wide text-atomic-ink hover:text-atomic-coral">Log in</a>
        @if (\App\Support\Registration::isOpen())
          <a href="/register" class="rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-5 py-2 font-sans text-sm font-bold text-atomic-cream shadow-atomic hover:bg-atomic-coral-dk">Sign up</a>
        @endif
      @endauth
    </div>
  </nav>
</header>
