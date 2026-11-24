{{-- Documentation landing screen. Group list is driven by ApiSpec. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <x-og-card
      title="Snapsbrew API Documentation"
      :description="$info['description']"
      url="{{ rtrim(config('app.url'), '/') }}/documentation"
      badge="API DOCS"
      headline="One API call, rendered in seconds"
      subhead="the developer reference" />
  </x-slot>

  <x-slot name="pageTitle">Snapsbrew API</x-slot>
  <x-slot name="tagline">{{ $info['title'] }} — render assets on demand</x-slot>

  <div>
    <p class="text-atomic-ink">{{ $info['description'] }}</p>

    {{-- The essentials --}}
    <div class="mt-9 rounded-2xl border-[3px] border-atomic-ink bg-atomic-ink p-7 text-atomic-cream sm:p-8">
      <p class="font-display text-xl tracking-tight text-atomic-mustard">The essentials</p>
      <dl class="mt-4 space-y-3 text-base">
        <div class="flex items-baseline justify-between gap-6 border-b-2 border-dotted border-atomic-cream/40 pb-3">
          <dt>Base URL</dt>
          <dd><code class="font-mono">{{ $info['baseUrl'] }}</code></dd>
        </div>
        <div class="flex items-baseline justify-between gap-6 border-b-2 border-dotted border-atomic-cream/40 pb-3">
          <dt>Version</dt>
          <dd><code class="font-mono">{{ $info['version'] }}</code></dd>
        </div>
        <div class="flex items-baseline justify-between gap-6 border-b-2 border-dotted border-atomic-cream/40 pb-3">
          <dt>Auth</dt>
          <dd>Bearer token with the <code class="font-mono">api_requests</code> ability</dd>
        </div>
        <div class="flex items-baseline justify-between gap-6">
          <dt>Rate limit</dt>
          <dd>60 requests / minute</dd>
        </div>
      </dl>
    </div>

    {{-- Quickstart --}}
    <h2 class="mt-12 font-display text-2xl tracking-tight">Make your first call</h2>
    <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>curl {{ $info['baseUrl'] }}/v1/auth \
  -H 'Authorization: Bearer &lt;your-token&gt;' \
  -H 'Accept: application/json'</code></pre>

    {{-- Machine-readable --}}
    <h2 class="mt-12 font-display text-2xl tracking-tight">Build against the spec</h2>
    <p class="mt-3 text-atomic-ink">
      Every page, the OpenAPI schema, the Postman collection and the Markdown
      exports are generated from one source of truth — they never drift apart.
    </p>
    <div class="mt-4 flex flex-wrap gap-3">
      <a href="{{ url('documentation/openapi.yaml') }}"
         class="inline-flex rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-6 py-2.5 text-base font-bold text-atomic-cream shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:bg-atomic-coral-dk hover:shadow-[3px_3px_0_0_#211d1a]">
        Download OpenAPI
      </a>
      <a href="{{ url('documentation/postman-collection') }}"
         class="inline-flex rounded-full border-[3px] border-atomic-ink bg-atomic-mustard px-6 py-2.5 text-base font-bold shadow-atomic transition hover:translate-x-[3px] hover:translate-y-[3px] hover:shadow-[3px_3px_0_0_#211d1a]">
        Download Postman
      </a>
      <a href="{{ url('documentation/api-reference.md') }}"
         class="inline-flex rounded-full border-[3px] border-atomic-ink px-6 py-2.5 text-base font-bold transition hover:bg-atomic-chrome">
        Full reference (.md)
      </a>
    </div>
  </div>

  {{-- Endpoint groups --}}
  <h2 class="mt-12 font-display text-2xl tracking-tight">Explore the endpoints</h2>
  <ul role="list" class="mt-4 border-t-[3px] border-atomic-ink">
    @foreach ($groups as $group)
      <li class="border-b-2 border-dashed border-atomic-ink/30">
        <a href="{{ url('documentation/'.$group['slug']) }}"
           class="flex items-baseline justify-between gap-6 py-4 transition hover:bg-atomic-chrome">
          <span class="min-w-0">
            <span class="font-display text-lg tracking-tight">{{ $group['title'] }}</span>
            <span class="ml-3 text-atomic-ink/70">{{ $group['tagline'] }}</span>
          </span>
          <span class="shrink-0 text-sm font-bold uppercase tracking-wide text-atomic-aqua-dk">
            {{ count($group['endpoints']) }} {{ \Illuminate\Support\Str::plural('endpoint', count($group['endpoints'])) }}
          </span>
        </a>
      </li>
    @endforeach
  </ul>
</x-documentation-layout>
