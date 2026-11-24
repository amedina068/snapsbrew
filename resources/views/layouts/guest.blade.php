<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.pwa-head')
        
        @if(isset($meta))
            {{ $meta }}
        @else
            <title>{{ config('app.name', 'Laravel') }}</title>
        @endif


        @if(isset($head))
            {!! $head !!}
        @endif

        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        {{-- Load fonts without blocking render; swap to all media once fetched --}}
        <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700;800&family=Righteous&display=swap">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700;800&family=Righteous&display=swap" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700;800&family=Righteous&display=swap"></noscript>
    </head>
    <body>
        <div id="app" class="bg-pattern">
            <div class="text-gray-900 antialiased">
                <div>
                  <div class="bg-atomic-cream">
                    <x-guest-header />
                    {{-- No guest page mounts a Vue component. `v-pre` stops Vue from compiling user text. --}}
                    <main v-pre>
                      {{ $slot }}
                    </main>
                    <x-guest-footer />
                  </div>
                </div>
            </div>
        </div>
        @stack('scripts')
    </body>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
          window.hljs.highlightAll()
        });
    </script>
</html>
