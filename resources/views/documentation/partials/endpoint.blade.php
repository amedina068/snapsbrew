@php
  /** @var array $endpoint */
  $methodColors = [
      'GET' => 'bg-atomic-aqua text-atomic-ink',
      'POST' => 'bg-atomic-coral text-atomic-cream',
      'PATCH' => 'bg-atomic-mustard text-atomic-ink',
      'PUT' => 'bg-atomic-mustard text-atomic-ink',
      'DELETE' => 'bg-atomic-pink text-atomic-ink',
  ];
  $methodClass = $methodColors[$endpoint['method']] ?? 'bg-atomic-chrome text-atomic-ink';
  $authLabels = ['bearer' => 'Bearer token', 'signature' => 'Signed URL', 'none' => 'Public — no auth'];
@endphp

<section id="{{ $endpoint['id'] }}" class="scroll-mt-24 lg:grid lg:grid-cols-[minmax(0,1fr)_40rem] lg:gap-x-12">

  {{-- Middle column: instructions --}}
  <div class="min-w-0">
    <div class="flex flex-wrap items-center gap-3">
      <span class="rounded-full border-[3px] border-atomic-ink px-3.5 py-1 font-display text-base tracking-tight {{ $methodClass }}">
        {{ $endpoint['method'] }}
      </span>
      <code class="font-mono text-base">{{ $endpoint['path'] }}</code>
    </div>

    <h3 class="mt-5 font-display text-2xl tracking-tight">{{ $endpoint['summary'] }}</h3>

    @foreach (preg_split('/\n\n+/', trim($endpoint['description'])) as $para)
      <p class="mt-3 text-atomic-ink">{{ $para }}</p>
    @endforeach

    {{-- Meta chips --}}
    <div class="mt-5 flex flex-wrap gap-2.5">
      <span class="rounded-full border-2 border-atomic-ink bg-atomic-chrome px-3.5 py-1.5 text-sm font-semibold">
        Auth: {{ $authLabels[$endpoint['auth']] ?? $endpoint['auth'] }}
      </span>
      @if (! empty($endpoint['abilities']))
        <span class="rounded-full border-2 border-atomic-ink bg-atomic-chrome px-3.5 py-1.5 text-sm font-semibold">
          Ability: {{ implode(', ', $endpoint['abilities']) }}
        </span>
      @endif
      @foreach ($endpoint['middleware'] ?? [] as $gate)
        <span class="rounded-full border-2 border-atomic-ink bg-atomic-mint px-3.5 py-1.5 text-sm font-semibold">
          Gate: {{ $gate }}
        </span>
      @endforeach
    </div>

    {{-- Parameters --}}
    @if (! empty($endpoint['parameters']))
      <h4 class="mt-9 font-display text-lg tracking-tight">Parameters</h4>
      <div class="-mx-1 mt-3 overflow-x-auto">
        <table class="w-full min-w-136 text-left text-base">
          <thead>
            <tr class="border-b-[3px] border-atomic-ink">
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Name</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">In</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Type</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Required</th>
              <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">Description</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($endpoint['parameters'] as $param)
              <tr class="border-b-2 border-dashed border-atomic-ink/30">
                <td class="py-3 pr-5"><code class="font-mono text-sm">{{ $param['name'] }}</code></td>
                <td class="py-3 pr-5">{{ $param['in'] }}</td>
                <td class="py-3 pr-5">{{ $param['type'] }}</td>
                <td class="py-3 pr-5">{{ ($param['required'] ?? false) ? 'Yes' : 'No' }}</td>
                <td class="py-3 leading-7">{{ $param['description'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    {{-- Request body --}}
    @if (! empty($endpoint['body']))
      <h4 class="mt-9 font-display text-lg tracking-tight">Request body</h4>
      <p class="mt-2 text-atomic-ink">Content-Type: <code class="font-mono text-sm">{{ $endpoint['body']['contentType'] }}</code></p>
      <div class="-mx-1 mt-3 overflow-x-auto">
        <table class="w-full min-w-xl text-left text-base">
          <thead>
            <tr class="border-b-[3px] border-atomic-ink">
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Field</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Type</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Required</th>
              <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Validation</th>
              <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">Description</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($endpoint['body']['fields'] as $field)
              <tr class="border-b-2 border-dashed border-atomic-ink/30">
                <td class="py-3 pr-5"><code class="font-mono text-sm">{{ $field['name'] }}</code></td>
                <td class="py-3 pr-5">{{ $field['type'] }}</td>
                <td class="py-3 pr-5">{{ ($field['required'] ?? false) ? 'Yes' : 'No' }}</td>
                <td class="py-3 pr-5"><code class="font-mono text-sm">{{ $field['rules'] ?? '—' }}</code></td>
                <td class="py-3 leading-7">{{ $field['description'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    {{-- Notes --}}
    @foreach ($endpoint['notes'] ?? [] as $note)
      <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
        <span aria-hidden="true">✦</span><span>{{ $note }}</span>
      </p>
    @endforeach
  </div>

  {{-- Right column: examples --}}
  <div class="mt-8 lg:mt-0">
    <div class="lg:sticky lg:top-24 space-y-5">
      @if (! empty($endpoint['examples']))
        <div>
          <p class="font-display text-sm tracking-tight text-atomic-aqua-dk">Example request</p>
          <div class="mt-2 space-y-3">
            @foreach ($endpoint['examples'] as $label => $code)
              <div>
                @if (! is_int($label))
                  <p class="text-xs font-bold uppercase tracking-wide text-atomic-ink/60">{{ $label }}</p>
                @endif
                <pre class="mt-1 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-4 py-3.5 text-sm leading-6 text-atomic-cream"><code>{{ $code }}</code></pre>
              </div>
            @endforeach
          </div>
        </div>
      @endif

      @if (! empty($endpoint['responses']))
        <div>
          <p class="font-display text-sm tracking-tight text-atomic-aqua-dk">Example responses</p>
          <div class="mt-2 space-y-3">
            @foreach ($endpoint['responses'] as $response)
              @php $ok = (int) $response['status'] < 300; @endphp
              <div class="overflow-hidden rounded-xl border-[3px] border-atomic-ink">
                <div class="flex flex-wrap items-center gap-2.5 bg-atomic-cream px-4 py-2.5">
                  <span class="rounded-full border-2 border-atomic-ink px-2.5 py-0.5 font-mono text-sm font-bold {{ $ok ? 'bg-atomic-mint' : 'bg-atomic-pink' }}">
                    {{ $response['status'] }}
                  </span>
                  <span class="text-sm font-medium">{{ $response['description'] }}</span>
                </div>
                <pre class="overflow-x-auto bg-atomic-ink px-4 py-3.5 text-sm leading-6 text-atomic-cream"><code>{{ json_encode($response['example'] ?? new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
              </div>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
</section>
