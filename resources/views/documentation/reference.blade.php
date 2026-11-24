{{-- Full API reference: object schemas + every endpoint, driven by ApiSpec. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>API Reference — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">API Reference</x-slot>
  <x-slot name="tagline">every object, every endpoint</x-slot>

  <p class="text-atomic-ink">
    The complete reference, generated from the live API spec. Download it whole
    as <a href="{{ url('documentation/api-reference.md') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">Markdown</a>,
    <a href="{{ url('documentation/openapi.yaml') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">OpenAPI</a> or
    <a href="{{ url('documentation/postman-collection') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">Postman</a>.
  </p>

  {{-- Object schemas --}}
  <h2 class="mt-12 font-display text-3xl tracking-tight">Objects</h2>
  <div class="mt-6 space-y-12">
    @foreach ($schemas as $name => $schema)
      <section id="schema-{{ \Illuminate\Support\Str::slug($name) }}" class="scroll-mt-24">
        <h3 class="font-display text-xl tracking-tight">{{ $name }}</h3>
        <p class="mt-2 text-atomic-ink">{{ $schema['description'] }}</p>
        <div class="-mx-1 mt-4 overflow-x-auto">
          <table class="w-full min-w-136 text-left text-base">
            <thead>
              <tr class="border-b-[3px] border-atomic-ink">
                <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Field</th>
                <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Type</th>
                <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">Description</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($schema['fields'] as $field)
                <tr class="border-b-2 border-dashed border-atomic-ink/30">
                  <td class="py-3 pr-5"><code class="font-mono text-sm">{{ $field['name'] }}</code></td>
                  <td class="py-3 pr-5">{{ $field['type'] }}</td>
                  <td class="py-3 leading-7">{{ $field['description'] }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>
    @endforeach
  </div>

  {{-- All endpoints --}}
  <h2 class="mt-14 font-display text-3xl tracking-tight">Endpoints</h2>
  @foreach ($groups as $group)
    <h3 class="mt-12 font-display text-2xl tracking-tight text-atomic-aqua-dk">{{ $group['title'] }}</h3>
    <div class="mt-6 space-y-14">
      @foreach ($group['endpoints'] as $endpoint)
        @unless ($loop->first)
          <hr class="border-t-[3px] border-atomic-ink/15">
        @endunless
        @include('documentation.partials.endpoint', ['endpoint' => $endpoint])
      @endforeach
    </div>
  @endforeach
</x-documentation-layout>
