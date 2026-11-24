{{-- Guide: Signed URLs. Hotlinkable, HMAC-signed render URLs backed by an API token. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>Signed URLs — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">Signed URLs</x-slot>
  <x-slot name="tagline">one hotlink, infinite renders</x-slot>

  <p class="text-atomic-ink">
    A signed URL is a plain image link you drop straight into an
    <code class="font-mono text-sm">&lt;img&gt;</code> tag or an
    <code class="font-mono text-sm">og:image</code> meta tag. There is no API call
    and no SDK. The browser requests the URL, and Snapsbrew renders the snap on
    the spot. It is the easiest way to ship social cards, email art, or any image
    that needs to change with your data.
  </p>

  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>Signed URLs are authenticated with an <strong>API token</strong>. You
    create a dedicated signed-URL key and use it as the secret that signs each
    link. That is different from
    <a href="{{ url('documentation/guides/embedded-forms') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">Embedded Forms</a>,
    where the signature is built into the link and no token is involved. Reach for
    signed URLs when your code generates the image, and embedded forms when a
    person fills it in.</span>
  </p>

  {{-- Step 1 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">1. Create a signed-URL key</h2>
  <p class="mt-3 text-atomic-ink">
    In the dashboard, open <strong>Settings → Developers</strong> and generate a
    <strong>Signed URL key</strong>. The key can do one thing and one thing only:
    render signed URLs. Copy it the moment it appears, because you will not be able
    to read it again. If a key ever leaks, revoke it from the same screen. A revoked
    key cannot render a new image. A link that already rendered keeps its image.
  </p>

  {{-- Step 2 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">2. Sign the request</h2>
  <p class="mt-3 text-atomic-ink">
    Sign the URL on your server. The signing key is a secret, so never sign in a
    browser.
  </p>

  <div class="mt-4 -mx-1 overflow-x-auto">
    <table class="w-full min-w-136 text-left text-base">
      <thead>
        <tr class="border-b-[3px] border-atomic-ink">
          <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Param</th>
          <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Required</th>
          <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">Description</th>
        </tr>
      </thead>
      <tbody>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">template_id</code></td>
          <td class="py-3 pr-5">Yes</td>
          <td class="py-3 leading-7">UUID of the template to render. The signing key must belong to an active user of the company that owns the template.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">variants</code></td>
          <td class="py-3 pr-5">No</td>
          <td class="py-3 leading-7">The JSON array of layer overrides, encoded as standard base64 (with <code class="font-mono text-sm">+</code>, <code class="font-mono text-sm">/</code> and <code class="font-mono text-sm">=</code> padding). Each item is a <code class="font-mono text-sm">layer</code> key and one override: <code class="font-mono text-sm">text</code>, <code class="font-mono text-sm">image_url</code>, <code class="font-mono text-sm">background_color</code>, <code class="font-mono text-sm">qr_content</code>, <code class="font-mono text-sm">barcode_content</code> or <code class="font-mono text-sm">hidden</code>.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">format</code></td>
          <td class="py-3 pr-5">No</td>
          <td class="py-3 leading-7"><code class="font-mono text-sm">png</code> (the default), <code class="font-mono text-sm">jpeg</code> or <code class="font-mono text-sm">webp</code>. Any other value renders png.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">transparent</code></td>
          <td class="py-3 pr-5">No</td>
          <td class="py-3 leading-7">Set to <code class="font-mono text-sm">1</code> to render on a transparent background. Valid for png and webp only.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">signature</code></td>
          <td class="py-3 pr-5">Yes</td>
          <td class="py-3 leading-7">The lowercase hex HMAC-SHA256 of the canonical string, signed with your signed URL key.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <p class="mt-7 text-atomic-ink">To sign a URL:</p>
  <ol class="mt-3 list-decimal space-y-2 pl-6 text-atomic-ink">
    <li>Encode the variants JSON as standard base64. Do not use base64url: the server decodes with PHP <code class="font-mono text-sm">base64_decode()</code>.</li>
    <li>Take every query parameter except <code class="font-mono text-sm">signature</code>.</li>
    <li>Sort the parameters by key in byte order.</li>
    <li>Join them as <code class="font-mono text-sm">key=value</code> with <code class="font-mono text-sm">&amp;</code>. Use the raw values. Do not URL-encode them in this string.</li>
    <li>Compute the HMAC-SHA256 of that string with your key. Use the lowercase hex digest as <code class="font-mono text-sm">signature</code>.</li>
    <li>Build the URL. URL-encode every value, because a standard base64 value can hold <code class="font-mono text-sm">+</code>, <code class="font-mono text-sm">/</code> and <code class="font-mono text-sm">=</code>.</li>
  </ol>

  <p class="mt-7 text-atomic-ink">
    Each example below builds the same URL. The order of the parameters in the
    final link does not matter, because the server sorts them again before it
    checks the signature.
  </p>

  @foreach (['PHP' => 'sign.php', 'JavaScript (Node.js)' => 'sign.mjs', 'Python' => 'sign.py', 'Ruby' => 'sign.rb'] as $language => $file)
    <h3 class="mt-7 font-display text-lg tracking-tight">{{ $language }}</h3>
    <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>{{ file_get_contents(resource_path('docs/signed-urls/'.$file)) }}</code></pre>
  @endforeach

  <p class="mt-7 text-atomic-ink">For example, in PHP:</p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>$url = snapsbrew_signed_url(
    '{{ rtrim(config('app.url'), '/') }}',
    config('services.snapsbrew.signed_url_key'), // your signed URL key
    'a1b2c3d4-0000-4000-8000-000000000000',      // template UUID
    [
        ['layer' =&gt; 'title', 'text' =&gt; $post-&gt;title],
        ['layer' =&gt; 'cover', 'image_url' =&gt; $post-&gt;cover_url],
    ],
);</code></pre>

  {{-- Step 3 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">3. Embed it</h2>
  <p class="mt-3 text-atomic-ink">
    Drop the link anywhere an image URL works. The endpoint answers with a
    redirect to the finished asset, so browsers, crawlers, and email clients all
    follow it without noticing the difference.
  </p>

  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>&lt;meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/signed-url/image.jpg?template_id=a1b2c3d4&amp;variants=...&amp;signature=..."&gt;

&lt;img src="{{ rtrim(config('app.url'), '/') }}/signed-url/image.jpg?template_id=a1b2c3d4&amp;variants=...&amp;signature=..." alt="Share card"&gt;</code></pre>

  {{-- Caching --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Caching</h2>
  <p class="mt-3 text-atomic-ink">
    The first request for a given link renders the snap and records one render
    in the usage log of the key owner. After that, Snapsbrew caches the result
    against the exact link, so every later request is served from cache. Change
    any parameter and you have a new link, which means a fresh render.
  </p>

  {{-- Gotchas --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Good to know</h2>
  <p class="mt-3 flex gap-2.5 border-l-[3px] border-atomic-coral pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>A bad, missing, or revoked signature does not return an error. It renders
    a neutral placeholder image instead. That stops a broken
    <code class="font-mono text-sm">&lt;img&gt;</code> tag from showing a 404, but
    it also means a silent placeholder is your hint that the signature is
    wrong.</span>
  </p>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span><code class="font-mono text-sm">variants</code> must be standard base64, and it must be
    part of the sorted string that you signed. A base64url value decodes to the
    wrong bytes, and a value that you URL-encoded before you signed it gives the
    wrong signature.</span>
  </p>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>The server checks the signature against the active signed URL keys of the
    company that owns the template. The key of any active user in the company
    works. The key of a deactivated user does not work.</span>
  </p>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>The server sets the <code class="font-mono text-sm">identifier</code> and
    <code class="font-mono text-sm">api_log_uuid</code> parameters itself. A link that
    contains one of them gets status 400.</span>
  </p>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>The route accepts {{ config('snapsbrew.signed_url_requests_per_ip') }} requests
    per minute from one IP address. A request over the limit gets status 429.</span>
  </p>
</x-documentation-layout>
