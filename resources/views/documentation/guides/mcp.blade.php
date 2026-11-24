{{-- Guide: MCP server. Connect an AI assistant to Snapsbrew over the Model Context Protocol. --}}
<x-documentation-layout>
  <x-slot name="meta">
    <title>MCP server — Snapsbrew API</title>
  </x-slot>

  <x-slot name="pageTitle">MCP server</x-slot>
  <x-slot name="tagline">let your assistant place the order</x-slot>

  <p class="text-atomic-ink">
    Snapsbrew speaks the Model Context Protocol, so any MCP client (Claude,
    Cursor, your own agent) can talk to your account directly. The assistant can
    browse your templates, preview a snap, and render the final image
    once you say yes. It uses the same API token as the rest of the API, and it
    works the moment you point a client at the server.
  </p>

  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>Previews are watermarked. The assistant calls
    <code class="font-mono text-sm">create-snap</code> for the final image only
    after you approve a preview.</span>
  </p>

  {{-- Connect --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Connect</h2>
  <div class="mt-4 -mx-1 overflow-x-auto">
    <table class="w-full min-w-136 text-left text-base">
      <tbody>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5 font-display text-base tracking-tight">Endpoint</td>
          <td class="py-3 leading-7"><code class="font-mono text-sm">{{ rtrim(config('app.url'), '/') }}/mcp</code></td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5 font-display text-base tracking-tight">Transport</td>
          <td class="py-3 leading-7">Streamable HTTP</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5 font-display text-base tracking-tight">Auth</td>
          <td class="py-3 leading-7">
            Two ways. A client that supports OAuth 2.0 finds the server on its
            own: give it the URL, and it opens a browser page where you approve
            the connection. Everything else sends a bearer token with the
            <code class="font-mono text-sm">api_requests</code> ability, the same
            key the REST API takes.
          </td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5 font-display text-base tracking-tight">Rate limit</td>
          <td class="py-3 leading-7">30 requests per minute.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <p class="mt-7 text-atomic-ink">
    A client that runs OAuth needs the URL and nothing else. It reads
    <code class="font-mono text-sm">/.well-known/oauth-protected-resource</code>,
    registers itself, and sends you to a page that names the application and
    what it will be able to do. Approve it there and the client holds its own
    token. You never paste a key, and you can end the connection later without
    changing the key your other tools use.
  </p>

  <p class="mt-7 text-atomic-ink">
    Every other client takes a name, a URL, and a header. Here is the shape of a
    typical config:
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>{
  "mcpServers": {
    "snapsbrew": {
      "url": "{{ rtrim(config('app.url'), '/') }}/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}</code></pre>

  {{-- Workflow --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">How a render goes</h2>
  <p class="mt-3 text-atomic-ink">
    The server nudges the assistant toward the same habit every time: look before
    you spend. A typical run goes like this.
  </p>
  <ol class="mt-4 space-y-3 text-atomic-ink">
    <li class="flex gap-3"><span class="font-display text-atomic-coral-dk">1.</span><span><code class="font-mono text-sm">whoami</code> confirms that the token works.</span></li>
    <li class="flex gap-3"><span class="font-display text-atomic-coral-dk">2.</span><span><code class="font-mono text-sm">list-templates</code> finds a template and its id.</span></li>
    <li class="flex gap-3"><span class="font-display text-atomic-coral-dk">3.</span><span><code class="font-mono text-sm">get-template-details</code> reads the layer keys to override.</span></li>
    <li class="flex gap-3"><span class="font-display text-atomic-coral-dk">4.</span><span><code class="font-mono text-sm">preview-snap</code> renders a watermarked preview. Iterate here until it looks right.</span></li>
    <li class="flex gap-3"><span class="font-display text-atomic-coral-dk">5.</span><span><code class="font-mono text-sm">create-snap</code> returns the clean, final image, once you approve.</span></li>
  </ol>
  <p class="mt-4 text-atomic-ink">
    No template yet? The assistant can design inline: <code class="font-mono text-sm">preview-design</code> renders a canvas plus a layers definition, and <code class="font-mono text-sm">create-snap-from-design</code> renders the final image. Nothing is saved. When the design will be used again, the assistant creates a template first.
  </p>

  {{-- Tools --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Tools</h2>
  <div class="mt-4 -mx-1 overflow-x-auto">
    <table class="w-full min-w-160 text-left text-base">
      <thead>
        <tr class="border-b-[3px] border-atomic-ink">
          <th class="whitespace-nowrap py-3 pr-5 font-display text-base font-normal tracking-tight">Tool</th>
          <th class="whitespace-nowrap py-3 font-display text-base font-normal tracking-tight">What it does</th>
        </tr>
      </thead>
      <tbody>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">whoami</code></td>
          <td class="py-3 leading-7">Returns your account and company.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">list-templates</code></td>
          <td class="py-3 leading-7">Lists your templates with their ids, sizes, and editable layers.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">get-template-details</code></td>
          <td class="py-3 leading-7">Returns one template's layer keys and the overrides each layer accepts.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">list-variant-properties</code></td>
          <td class="py-3 leading-7">The full catalog of overrides you can apply in <code class="font-mono text-sm">variants</code>, with value types and examples.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">preview-template</code></td>
          <td class="py-3 leading-7">Renders a watermarked preview of a template, with optional overrides.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">preview-snap</code></td>
          <td class="py-3 leading-7">Renders a watermarked preview of the snap you are about to create.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">create-snap</code></td>
          <td class="py-3 leading-7">Renders the final, un-watermarked snap.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">preview-design</code></td>
          <td class="py-3 leading-7">Renders a watermarked preview of a design described inline (a canvas plus layers) with no template. Echoes the exact design it rendered.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">create-snap-from-design</code></td>
          <td class="py-3 leading-7">Renders the final snap from an inline design. No template is saved.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">describe-layers</code></td>
          <td class="py-3 leading-7">The layer object shape and property vocabulary for building or editing templates.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">design-guide</code></td>
          <td class="py-3 leading-7">How to compose layers well on any canvas — layer order, typography, colour, and composition recipes.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">platform-guide</code></td>
          <td class="py-3 leading-7">What a platform does to your image after upload: the size to start from, how it crops, the UI safe zones over Stories, Reels, TikTok and banners, and the file limits.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">image-purpose-guide</code></td>
          <td class="py-3 leading-7">The rules for what the image is <em>for</em> — link preview (with the meta tags), carousel, feed post, avatar, banner, thumbnail, ad, product shot, email header, blog hero, slide.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">list-template-sizes</code></td>
          <td class="py-3 leading-7">Every canvas size preset — Instagram, Facebook, X, LinkedIn, Threads, Pinterest, TikTok, YouTube, Snapchat, WhatsApp, web and email, display ads, and print — with its dimensions, aspect ratio, and safe margin.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">create-template</code></td>
          <td class="py-3 leading-7">Creates a new template from a title, a <code class="font-mono text-sm">size</code> preset (or explicit dimensions), and layers.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">edit-template</code></td>
          <td class="py-3 leading-7">Updates a template in your company. Existing snaps depend on it, so use it deliberately.</td>
        </tr>
        <tr class="border-b-2 border-dashed border-atomic-ink/30">
          <td class="py-3 pr-5"><code class="font-mono text-sm">publish-template</code></td>
          <td class="py-3 leading-7">Makes a template draft live. An edit does not reach new snaps until you publish it.</td>
        </tr>
      </tbody>
    </table>
  </div>

  {{-- Variants --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Overrides</h2>
  <p class="mt-3 text-atomic-ink">
    You customize a snap by passing <code class="font-mono text-sm">variants</code>:
    an array of objects, each one targeting a layer by its key with a single
    change.
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>[
  { "layer": "headline", "text": "Summer sale" },
  { "layer": "logo", "image_url": "https://example.com/logo.png" },
  { "layer": "backdrop", "background_color": "#ff5a36" },
  { "layer": "ticket", "qr_content": "https://example.com/rsvp" },
  { "layer": "ribbon", "hidden": true }
]</code></pre>
  <p class="mt-4 flex gap-2.5 border-l-[3px] border-atomic-mustard pl-4 text-atomic-ink">
    <span aria-hidden="true">✦</span>
    <span>Run <code class="font-mono text-sm">list-variant-properties</code> for the
    full override catalog, and <code class="font-mono text-sm">get-template-details</code>
    for the layer keys on a specific template.</span>
  </p>

  {{-- Errors --}}
  <h2 class="mt-10 font-display text-2xl tracking-tight">Errors</h2>
  <p class="mt-3 text-atomic-ink">
    An agent reads an error to decide what to call next, so every tool that
    fails answers with the same JSON object instead of a sentence.
  </p>
  <pre class="mt-4 overflow-x-auto rounded-xl border-[3px] border-atomic-ink bg-atomic-ink px-5 py-4 text-sm leading-6 text-atomic-cream"><code>{
  "error": {
    "code": "template_not_found",
    "message": "No template with id `a1b2` exists in your account.",
    "fix": "Call `list-templates` to read the template ids in your account. Then call this tool again with an id from that list.",
    "retryable": false,
    "details": { "template_id": "a1b2" }
  }
}</code></pre>
  <dl class="mt-5 space-y-3 text-atomic-ink">
    <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
      <dt class="shrink-0 sm:w-32"><code class="font-mono text-sm font-bold">code</code></dt>
      <dd class="leading-7">A stable identifier. Branch on this. The wording of
      <code class="font-mono text-sm">message</code> can change; the code cannot.</dd>
    </div>
    <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
      <dt class="shrink-0 sm:w-32"><code class="font-mono text-sm font-bold">message</code></dt>
      <dd class="leading-7">What went wrong, for the person the agent reports to.</dd>
    </div>
    <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
      <dt class="shrink-0 sm:w-32"><code class="font-mono text-sm font-bold">fix</code></dt>
      <dd class="leading-7">The next call to make, in the imperative. It names the
      tool to call and what to change.</dd>
    </div>
    <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
      <dt class="shrink-0 sm:w-32"><code class="font-mono text-sm font-bold">retryable</code></dt>
      <dd class="leading-7">Whether the identical call can succeed later. Only a
      request that holds your <code class="font-mono text-sm">idempotency_key</code>
      right now reads true. Stop a retry loop on every other error.</dd>
    </div>
    <div class="flex flex-col gap-1 sm:flex-row sm:gap-4">
      <dt class="shrink-0 sm:w-32"><code class="font-mono text-sm font-bold">details</code></dt>
      <dd class="leading-7">The values you sent, and the choices you can pick from,
      when they help build the next call. Absent when there is nothing to add.</dd>
    </div>
  </dl>
</x-documentation-layout>
