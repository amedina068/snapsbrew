{{--
  OG / social-card meta block. Renders the page <title>, description, canonical,
  and Open Graph + Twitter tags. The og:image is a Snapsbrew signed URL rendered
  from the "Snapsbrew OG / Social Card" template with per-page variants (badge /
  headline / subhead). Falls back to the static snapshot when no signing key is set.

  Usage:
    <x-og-card
      title="Snapsbrew Docs"
      description="…"
      url="{{ url('documentation') }}"
      badge="DOCS"
      headline="Read the menu"
      subhead="every endpoint, one page" />
--}}
@props([
    'title',
    'description' => null,
    'url' => null,
    'badge' => null,
    'headline' => null,
    'subhead' => null,
    'image' => null,
    'type' => 'website',
])
@php
    $variants = collect([
        ['layer' => 'badge', 'text' => $badge],
        ['layer' => 'headline', 'text' => $headline],
        ['layer' => 'subhead', 'text' => $subhead],
    ])->filter(fn ($v) => filled($v['text']))->values()->all();

    $ogImage = $image
        ?? \App\Support\SignedImageUrl::make($variants)
        ?? url('images/snapsbrew-snapshot.webp');
@endphp
<title>{{ $title }}</title>
@isset($description)
<meta name="description" content="{{ $description }}">
@endisset
@isset($url)
<link rel="canonical" href="{{ $url }}">
<meta property="og:url" content="{{ $url }}">
<meta property="twitter:url" content="{{ $url }}">
@endisset
<meta property="og:type" content="{{ $type }}">
<meta property="og:title" content="{{ $title }}">
@isset($description)<meta property="og:description" content="{{ $description }}">@endisset
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="twitter:card" content="summary_large_image">
<meta property="twitter:title" content="{{ $title }}">
@isset($description)<meta property="twitter:description" content="{{ $description }}">@endisset
<meta property="twitter:image" content="{{ $ogImage }}">
{{ $slot ?? '' }}
