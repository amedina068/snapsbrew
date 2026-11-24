{{-- Guide: Embedded Forms. Drop-in render forms backed by a pre-signed route. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>Embedded Forms — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">Embedded Forms</x-slot>
  <x-slot name="tagline">let anyone render, no key required</x-slot>

  <p class="text-atomic-ink">
    An embedded form turns a template into a fill-in-the-blanks widget you can
    hand to a teammate, a client, or the public. Whoever opens it edits only the
    layers you chose to expose and renders a snap, and they never touch an API
    token. You, the form owner, pay for the render.
  </p>

  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>An embedded form is authenticated by the link itself. The signature is
    built into the URL, so the form needs no API key. That is the opposite of
    <a href="{{ url('documentation/guides/signed-urls') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">Signed URLs</a>,
    where you sign each request with an API token. Use an embedded form when a
    person fills in the values, and a signed URL when your code does.</span>
  </p>

  {{-- Step 1 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">1. Create a form</h2>
  <p class="mt-3 text-atomic-ink">
    Open a template, or a template collection, and choose <strong>Forms → New
    form</strong>. Tick the layers you want people to edit. Anything you leave
    unticked stays locked to the template default. A <strong>template form</strong>
    renders a single snap, and a <strong>collection form</strong> renders one snap
    for each template in the collection.
  </p>

  {{-- Step 2 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">2. Grab the signed URLs</h2>
  <p class="mt-3 text-atomic-ink">
    Every form comes with two ready-to-use links:
  </p>
  <div class="mt-4 -mx-1 overflow-x-auto">
    <table class="w-full min-w-136 text-left text-base">
      <thead>
        <tr class="border-b-[3px] border-atomic-ink">
          <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Field</th>
          <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">Use</th>
        </tr>
      </thead>
      <tbody>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">signed_url</code></td>
          <td class="py-3 leading-7">The hosted form page. Drop it in an <code class="font-mono text-sm">&lt;iframe&gt;</code> to embed the whole widget.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">submit_signed_url</code></td>
          <td class="py-3 leading-7">The POST target. Send layer values straight to it if you would rather build your own UI.</td>
        </tr>
      </tbody>
    </table>
  </div>

  {{-- Step 3 --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">3. Embed it, or post to it</h2>
  <p class="mt-3 text-atomic-ink">
    Drop the form into a page with an iframe:
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>&lt;iframe src="{{ rtrim(config('app.url'), '/') }}/embedded-form/&lt;formUuid&gt;?signature=&lt;sig&gt;"
        width="100%" height="640" frameborder="0"&gt;&lt;/iframe&gt;</code></pre>

  <p class="mt-7 text-atomic-ink">
    Or send the layer values yourself:
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>curl -X POST '{{ rtrim(config('app.url'), '/') }}/api/v1/embedded-forms/&lt;formUuid&gt;?signature=&lt;sig&gt;' \
  -F 'layers[headline]=Summer sale' \
  -F 'layers[logo]=@logo.png'</code></pre>

  {{-- Layer keys --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Layer keys</h2>
  <p class="mt-3 text-atomic-ink">
    Template forms address a layer by its plain key, like
    <code class="font-mono text-sm">headline</code>. Collection forms put the
    template UUID in front of the key
    (<code class="font-mono text-sm">{templateUuid}__{layerKey}</code>), so the same
    key on two templates stays separate. Values key by layer type:
    <code class="font-mono text-sm">image → image_url</code> (a file),
    <code class="font-mono text-sm">text → text</code>,
    <code class="font-mono text-sm">empty → background_color</code>, and
    <code class="font-mono text-sm">qr_code → qr_content</code>. Any layer the form
    was not set up with is quietly ignored.
  </p>

  {{-- Usage --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Usage</h2>
  <p class="mt-3 text-atomic-ink">
    Each render counts in the usage log of the form owner: one render for a
    template form, or one render per template for a collection form. The person
    who fills in the form never needs an account.
  </p>

  {{-- Gotchas --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Good to know</h2>
  <p class="mt-3 flex gap-2.5 border-l-[3px] border-atomic-coral pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>A signature that has been changed or is missing returns a
    <strong>404</strong>. There is no placeholder fallback the way there is with
    signed URLs, so always use the exact
    <code class="font-mono text-sm">signed_url</code> the form handed you.</span>
  </p>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>Collection forms hand back a bare array of snap objects rather than a
    <code class="font-mono text-sm">data</code>-wrapped object, and the submit
    endpoint is not rate limited. The full request and response shapes live in the
    <a href="{{ url('documentation/embedded-forms') }}" class="underline decoration-atomic-coral decoration-2 underline-offset-2">Embedded Forms endpoint reference</a>.</span>
  </p>
</x-documentation-layout>
