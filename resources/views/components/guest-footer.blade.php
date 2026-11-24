<footer class="bg-atomic-ink">
  <div class="h-3 w-full" aria-hidden="true"
       style="background:repeating-linear-gradient(-45deg,#f0573f 0 22px,#f5ecd7 22px 44px);"></div>

  <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-10 sm:flex-row sm:items-center sm:justify-between lg:px-8">
    <a href="/" class="flex items-center gap-2">
      <span aria-hidden="true" class="font-display text-xl text-atomic-coral">&#10022;</span>
      <span class="font-display text-xl text-atomic-cream">{{ config('app.name') }}</span>
    </a>
    <ul class="flex gap-6 text-sm">
      <li><a href="/documentation" class="text-atomic-cream/80 hover:text-atomic-coral">Documentation</a></li>
      <li><a href="/llms.txt" class="text-atomic-cream/80 hover:text-atomic-coral">llms.txt</a></li>
    </ul>
  </div>
</footer>
