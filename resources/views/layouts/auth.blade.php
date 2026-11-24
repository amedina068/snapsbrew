<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="overflow-y-scroll">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.pwa-head')
        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Pacifico&family=Poppins:wght@400;500;600;700;800&family=Righteous&display=swap" rel="stylesheet">
        <!-- Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Scripts -->
    </head>
    <body class="font-sans">
        <div id="app">
            {{-- No page of this layout mounts a Vue component. `v-pre` stops Vue from compiling user text, for example an OAuth client name. --}}
            <div v-pre>
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
