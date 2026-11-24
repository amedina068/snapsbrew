{{-- Generic endpoint-group screen. Rendered for every ApiSpec group. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <x-og-card
      :title="$group['title'].' — Snapsbrew API'"
      :description="\Illuminate\Support\Str::of($group['description'])->stripTags()->squish()->limit(160)"
      :url="url('documentation/'.$group['slug'])"
      badge="API DOCS"
      :headline="$group['title']"
      :subhead="$group['tagline']" />
  </x-slot>

  <x-slot name="pageTitle">{{ $group['title'] }}</x-slot>
  <x-slot name="tagline">{{ $group['tagline'] }}</x-slot>

  <div>
    @foreach (preg_split('/\n\n+/', trim($group['description'])) as $para)
      <p class="text-atomic-ink{{ ! $loop->first ? ' mt-4' : '' }}">{{ $para }}</p>
    @endforeach
  </div>

  {{-- Download this screen --}}
  <div class="mt-7 flex flex-wrap gap-3">
    <a href="{{ url('documentation/'.$group['slug'].'.md') }}"
       class="inline-flex items-center gap-1.5 rounded-full border-[3px] border-atomic-ink bg-atomic-mustard px-5 py-2.5 text-base font-bold shadow-[3px_3px_0_0_#211d1a] transition hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none">
      Copy as Markdown
    </a>
    <a href="{{ url('documentation/openapi.yaml') }}"
       class="inline-flex items-center gap-1.5 rounded-full border-[3px] border-atomic-ink px-5 py-2.5 text-base font-bold transition hover:bg-atomic-chrome">
      OpenAPI
    </a>
    <a href="{{ url('documentation/postman-collection') }}"
       class="inline-flex items-center gap-1.5 rounded-full border-[3px] border-atomic-ink px-5 py-2.5 text-base font-bold transition hover:bg-atomic-chrome">
      Postman
    </a>
  </div>

  {{-- On-page endpoint index --}}
  @if (count($group['endpoints']) > 1)
    <div class="mt-9">
      <p class="font-display text-base tracking-tight text-atomic-aqua-dk">On this page</p>
      <ul role="list" class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
        @foreach ($group['endpoints'] as $endpoint)
          <li>
            <a href="#{{ $endpoint['id'] }}" class="font-medium underline decoration-atomic-coral decoration-2 underline-offset-2">
              {{ $endpoint['method'] }} {{ $endpoint['summary'] }}
            </a>
          </li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="mt-12 space-y-14">
    @foreach ($group['endpoints'] as $endpoint)
      @unless ($loop->first)
        <hr class="border-t-[3px] border-atomic-ink/15">
      @endunless
      @include('documentation.partials.endpoint', ['endpoint' => $endpoint])
    @endforeach
  </div>
</x-documentation-layout>
