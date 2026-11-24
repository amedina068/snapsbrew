{{-- Sidebar navigation. Sections come from the layout, derived from ApiSpec. --}}
<nav aria-label="Documentation">
  @foreach ($navSections as $heading => $items)
    <p class="font-display text-sm tracking-tight text-atomic-aqua-dk{{ ! $loop->first ? ' mt-6' : '' }}">
      {{ $heading }}
    </p>
    <ul role="list" class="mt-2 space-y-1">
      @foreach ($items as $item)
        @php $isActive = $current === trim($item['url'], '/'); @endphp
        <li>
          <a href="{{ url($item['url']) }}"
             @if ($isActive) aria-current="page" @endif
             class="block rounded-lg border-2 px-3 py-1.5 text-sm font-medium transition
               {{ $isActive
                   ? 'border-atomic-ink bg-atomic-mustard text-atomic-ink'
                   : 'border-transparent text-atomic-ink hover:border-atomic-ink hover:bg-atomic-chrome' }}">
            {{ $item['label'] }}
          </a>
        </li>
      @endforeach
    </ul>
  @endforeach

  <p class="mt-6 font-display text-sm tracking-tight text-atomic-aqua-dk">Downloads</p>
  <ul role="list" class="mt-2 space-y-1">
    <li>
      <a href="{{ url('documentation/openapi.yaml') }}"
         class="block rounded-lg border-2 border-transparent px-3 py-1.5 text-sm font-medium text-atomic-ink transition hover:border-atomic-ink hover:bg-atomic-chrome">
        OpenAPI schema
      </a>
    </li>
    <li>
      <a href="{{ url('documentation/postman-collection') }}"
         class="block rounded-lg border-2 border-transparent px-3 py-1.5 text-sm font-medium text-atomic-ink transition hover:border-atomic-ink hover:bg-atomic-chrome">
        Postman collection
      </a>
    </li>
    <li>
      <a href="{{ url('documentation/api-reference.md') }}"
         class="block rounded-lg border-2 border-transparent px-3 py-1.5 text-sm font-medium text-atomic-ink transition hover:border-atomic-ink hover:bg-atomic-chrome">
        Full reference (Markdown)
      </a>
    </li>
  </ul>
</nav>
