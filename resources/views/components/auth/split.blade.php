{{--
  The frame of the sign-in, sign-up and setup pages. The form is in the
  first column. On wide screens a diner panel with the sales points is in
  the second column.
--}}
@props([
    'kicker',
    'headline',
    'points' => [],
    'footer' => null,
    'homeLink' => true,
])
<div class="grid min-h-screen bg-atomic-cream lg:grid-cols-[minmax(0,40rem)_1fr]">
  <main class="flex flex-col justify-center px-6 py-12 sm:px-12 lg:px-16">
    <div class="mx-auto w-full max-w-md">
      @if ($homeLink)
        <a href="/" class="focus-ring inline-flex items-center gap-2 rounded-sm">
          <span aria-hidden="true" class="font-display text-3xl text-atomic-coral">&#10022;</span>
          <span class="font-display text-3xl text-atomic-ink">{{ config('app.name') }}</span>
        </a>
      @else
        <p class="inline-flex items-center gap-2">
          <span aria-hidden="true" class="font-display text-3xl text-atomic-coral">&#10022;</span>
          <span class="font-display text-3xl text-atomic-ink">{{ config('app.name') }}</span>
        </p>
      @endif

      {{ $slot }}
    </div>
  </main>

  <aside class="relative hidden overflow-hidden border-l-[3px] border-atomic-ink bg-atomic-ink lg:flex lg:flex-col lg:justify-center lg:px-14 xl:px-20">
    <p class="font-script text-2xl text-atomic-mustard [text-shadow:0_0_10px_#f2b134]">{{ $kicker }}</p>
    <h2 class="mt-4 max-w-md font-display text-5xl leading-tight text-atomic-cream">{{ $headline }}</h2>

    @if ($points)
      <ul class="mt-10 max-w-lg space-y-4">
        @foreach ($points as $point)
          <li class="flex items-start gap-3 text-lg text-atomic-cream/90">
            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full border-[3px] border-atomic-ink bg-atomic-mustard text-atomic-ink">
              <x-icon name="check" class="size-3.5" stroke="3.5" />
            </span>
            {{ $point }}
          </li>
        @endforeach
      </ul>
    @endif

    @if ($footer)
      <p class="mt-12 font-script text-2xl text-atomic-aqua [text-shadow:0_0_10px_#1fb5b8]">{{ $footer }}</p>
    @endif

    <div class="absolute inset-x-0 bottom-0 h-3 bg-[repeating-linear-gradient(-45deg,#f0573f_0_22px,#f5ecd7_22px_44px)]" aria-hidden="true"></div>
  </aside>
</div>
