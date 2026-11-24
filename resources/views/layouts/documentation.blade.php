@php
  use App\Support\ApiDocs\ApiSpec;

  $docGroups = ApiSpec::groups();
  $current = trim(request()->path(), '/');

  // Nav is built from the same spec the screens render from.
  $navSections = [
      'Get started' => [
          ['label' => 'Overview', 'url' => 'documentation'],
          ['label' => 'API reference', 'url' => 'documentation/api-reference'],
      ],
      'Guides' => [
          ['label' => 'MCP server', 'url' => 'documentation/guides/mcp'],
          ['label' => 'Embedded forms', 'url' => 'documentation/guides/embedded-forms'],
          ['label' => 'Signed URLs', 'url' => 'documentation/guides/signed-urls'],
          ['label' => 'Rate limits', 'url' => 'documentation/rate-limit'],
          ['label' => 'Troubleshooting', 'url' => 'documentation/troubleshooting'],
      ],
      'Endpoints' => collect($docGroups)->map(fn ($g) => [
          'label' => $g['title'],
          'url' => 'documentation/'.$g['slug'],
      ])->all(),
  ];
@endphp
<!DOCTYPE html>
<html class="h-full antialiased" lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('partials.pwa-head')
  @isset($meta){{ $meta }}@else<title>{{ config('app.name') }} API Documentation</title>@endisset

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700&family=Righteous&display=swap">

  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-[#fdfaf1] font-sans text-[1.05rem] leading-8 text-atomic-ink">
  <div id="app" class="isolate min-h-dvh">

    {{-- Header --}}
    <header class="sticky top-0 z-40 border-b-[3px] border-atomic-ink bg-atomic-ink">
      <div class="flex items-center justify-between gap-4 px-6 py-4 lg:px-8">
        <a href="{{ url('documentation') }}" class="flex items-baseline gap-2">
          <span class="font-display text-xl tracking-tight text-atomic-cream">SNAPSBREW</span>
          <span class="font-script text-lg text-atomic-mustard">docs</span>
        </a>

        <nav class="flex items-center gap-3" aria-label="Documentation utilities">
          <a href="{{ url('documentation/api-reference.md') }}"
             class="hidden rounded-full border-[3px] border-atomic-cream px-4 py-1.5 text-sm font-semibold text-atomic-cream transition hover:bg-atomic-cream hover:text-atomic-ink sm:inline-flex">
            Markdown
          </a>
          <a href="{{ url('documentation/openapi.yaml') }}"
             class="hidden rounded-full border-[3px] border-atomic-cream px-4 py-1.5 text-sm font-semibold text-atomic-cream transition hover:bg-atomic-cream hover:text-atomic-ink sm:inline-flex">
            OpenAPI
          </a>
          <a href="{{ url('/') }}"
             class="inline-flex rounded-full border-[3px] border-atomic-ink bg-atomic-coral px-4 py-1.5 text-sm font-bold text-atomic-cream shadow-[3px_3px_0_0_#f5ecd7] transition hover:translate-x-[2px] hover:translate-y-[2px] hover:bg-atomic-coral-dk hover:shadow-none">
            Back to app
          </a>
        </nav>
      </div>
    </header>

    <div class="flex">

      {{-- Desktop sidebar --}}
      <aside class="hidden w-64 shrink-0 border-r-[3px] border-atomic-ink lg:block">
        <div class="sticky top-19 max-h-[calc(100dvh-4.75rem)] overflow-y-auto px-5 py-8">
          @include('documentation.partials.nav', ['navSections' => $navSections, 'current' => $current])
        </div>
      </aside>

      {{-- Content --}}
      <main class="min-w-0 flex-1 px-6 py-10 lg:px-12 lg:py-14">

        {{-- Mobile nav --}}
        <details class="group mb-8 lg:hidden">
          <summary class="flex cursor-pointer list-none items-center justify-between rounded-full border-[3px] border-atomic-ink bg-atomic-mustard px-5 py-2.5 font-display tracking-tight">
            <span>Browse the docs</span>
            <span class="text-sm font-bold group-open:hidden">Open</span>
            <span class="hidden text-sm font-bold group-open:inline">Close</span>
          </summary>
          <div class="mt-4 border-l-[3px] border-atomic-ink pl-5">
            @include('documentation.partials.nav', ['navSections' => $navSections, 'current' => $current])
          </div>
        </details>

        <h1 class="font-display text-4xl tracking-tight sm:text-5xl">{{ $pageTitle }}</h1>
        @isset($tagline)
          <p class="mt-3 font-script text-2xl text-atomic-aqua-dk">{{ $tagline }}</p>
        @endisset

        {{-- No documentation page mounts a Vue component. `v-pre` stops Vue from compiling the text of the page. --}}
        <div v-pre class="mt-9">
          {{ $slot }}
        </div>
      </main>
    </div>

    <footer class="border-t-[3px] border-atomic-ink bg-atomic-aqua">
      <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-6 lg:px-8">
        <p class="font-display tracking-tight text-atomic-ink">SNAPSBREW API — open 24 hours</p>
        <p class="text-sm font-medium text-atomic-ink">Questions? {{ \App\Support\ApiDocs\ApiSpec::info()['contactEmail'] }}</p>
      </div>
    </footer>
  </div>
  @stack('scripts')
</body>
</html>
