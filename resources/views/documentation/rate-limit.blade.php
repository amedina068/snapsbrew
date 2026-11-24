{{-- Static info page: rate limits. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>Rate limits — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">Rate limits</x-slot>
  <x-slot name="tagline">60 a minute, on the house</x-slot>

  <p class="text-atomic-ink">
    Every bearer endpoint allows 60 requests per minute for one user. A request
    with no user counts against its IP address. Over the limit, the API returns
    HTTP 429 instead of the usual body.
  </p>
  <p class="mt-4 text-atomic-ink">
    Some endpoints have a second, lower limit. Both limits apply.
  </p>
  <ul class="mt-4 list-disc space-y-2 pl-6 text-atomic-ink">
    <li>The two preview endpoints allow 10 requests per minute for one user.</li>
    <li>The editor document endpoints allow 30 requests per minute for one user and 180 for one company.</li>
  </ul>
  <p class="mt-4 text-atomic-ink">
    Every response carries headers telling you how much budget is left for the
    current minute:
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>X-RateLimit-Limit: 60
X-RateLimit-Remaining: 40</code></pre>

  <p class="mt-7 text-atomic-ink">
    Once the limit is reached, the response tells you when to try again:
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>Retry-After: 11            # seconds
X-RateLimit-Reset: 1669844631   # unix timestamp</code></pre>

</x-documentation-layout>
