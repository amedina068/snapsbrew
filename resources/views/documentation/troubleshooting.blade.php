{{-- Static info page: troubleshooting. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>Troubleshooting — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">Troubleshooting</x-slot>
  <x-slot name="tagline">stuck? we will pull the ticket</x-slot>

  <p class="text-atomic-ink">
    Hit a wall? Email
    <a href="mailto:{{ \App\Support\ApiDocs\ApiSpec::info()['contactEmail'] }}"
       class="underline decoration-atomic-coral decoration-2 underline-offset-2">{{ \App\Support\ApiDocs\ApiSpec::info()['contactEmail'] }}</a>
    and we will dig in.
  </p>

  <p class="mt-4 text-atomic-ink">
    Attach the <code class="font-mono text-sm">X-Request-Uuid</code> header from
    the response you are asking about. It pins your exact request in our logs so
    we can troubleshoot fast.
  </p>

  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>X-Request-Uuid: 9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f</code></pre>
</x-documentation-layout>
