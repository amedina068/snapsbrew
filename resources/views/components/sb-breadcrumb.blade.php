{{-- Kept for older call sites. New code uses <x-ui.breadcrumb>. --}}
@props([
    'items' => [],
    'homeUrl' => url('/'),
])
<x-ui.breadcrumb :items="$items" :home-url="$homeUrl" />
