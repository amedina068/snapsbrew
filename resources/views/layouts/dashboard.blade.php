@php
  // A page passes a plain title, or a block with breadcrumbs and its own <h1>.
  // The document title reads the text of that <h1>, or the plain title.
  $pageHeading = (string) $pageTitle;
  $hasOwnHeading = str_contains($pageHeading, '<h1');
  $titleSource = $hasOwnHeading && preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $pageHeading, $match) ? $match[1] : $pageHeading;
  $documentTitle = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($titleSource), ENT_QUOTES)));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('partials.pwa-head')
  <title>{{ $documentTitle !== '' ? $documentTitle.' - '.config('app.name') : config('app.name') }}</title>
  <!-- Styles -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700;800&family=Righteous&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
  <body class="h-full bg-slate-50">

  <div id="app">
    <div class="min-h-full bg-slate-50">
      <x-dashboard-topbar />
    
      <div class="py-4">
        {{-- Not a <header>: the top bar is the only banner landmark of the page. --}}
        <div>
          <div class="px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col space-y-6 sm:space-y-0 sm:flex-row sm:justify-between sm:items-center">
              @if ($hasOwnHeading)
                <div v-pre>{{ $pageTitle }}</div>
              @else
                <h1 v-pre class="text-lg tracking-wide text-gray-900">{{ $pageTitle }}</h1>
              @endif

              @if(isset($pageActions))
                <div v-pre class="contents">{{ $pageActions }}</div>
              @endif
            </div>
          </div>
        </div>
        <main>
          <div class="px-4 sm:px-6 lg:px-8">
            {{-- The messages can hold user text, so v-pre stops Vue from compiling them. --}}
            @if (session('success'))
              <div v-pre class="mt-4 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if (session('error'))
              <div v-pre class="mt-4 rounded-lg border-l-4 border-l-red-500 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ session('error') }}</div>
            @endif
            {{--
              Vue compiles the HTML inside #app as a template, so a `{{ }}` in
              user text runs as JavaScript. `v-pre` turns compilation off for
              the page content. A page that mounts a Vue component in its
              content passes `vue-slot`, and then puts `v-pre` on each element
              that prints user text.
            --}}
            @if ($attributes->has('vue-slot'))
              <div class="py-4">
                {{ $slot }}
              </div>
            @else
              <div v-pre class="py-4">
                {{ $slot }}
              </div>
            @endif
          </div>
        </main>
      </div>
    </div>
    <template-actions-modal />
  </div>

  @if(isset($scripts))
    {{ $scripts }}
  @endif
</body>
</html>
