<template>
  <div>
    <!-- Token: paste once, substituted into every snippet, browser-only -->
    <div class="rounded-lg border border-gray-950/10 bg-gray-50 p-4">
      <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <label :for="tokenFieldId" class="text-xs font-medium uppercase tracking-wider text-gray-500">
          Your access token
        </label>
        <span class="text-xs text-gray-500">Stays in this browser — never sent anywhere</span>
      </div>
      <div class="mt-2 flex gap-2">
        <input
          :id="tokenFieldId"
          v-model.trim="token"
          :type="reveal ? 'text' : 'password'"
          spellcheck="false"
          autocomplete="off"
          placeholder="Paste a token to fill the snippets below"
          class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 shadow-xs focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
        />
        <button
          type="button"
          class="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 shadow-xs hover:text-gray-900"
          @click="reveal = !reveal"
        >
          {{ reveal ? 'Hide' : 'Show' }}
        </button>
      </div>
      <p class="mt-2 text-xs text-gray-500">
        Create a token above, copy it on creation, then paste it here. The placeholder
        <span class="font-mono text-gray-700">&lt;your-token&gt;</span> is used until you do.
      </p>
    </div>

    <!-- Connection facts -->
    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
      <div class="rounded-lg border border-gray-950/10 p-3">
        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">Server URL</dt>
        <dd class="mt-1 flex items-center justify-between gap-2">
          <span class="break-all font-mono text-sm text-gray-900">{{ url }}</span>
          <button type="button" class="shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-700" @click="copy(url, 'url')">
            {{ copiedKey === 'url' ? 'Copied' : 'Copy' }}
          </button>
        </dd>
      </div>
      <div class="rounded-lg border border-gray-950/10 p-3">
        <dt class="text-xs font-medium uppercase tracking-wider text-gray-500">Auth header</dt>
        <dd class="mt-1 flex items-center justify-between gap-2">
          <span class="break-all font-mono text-sm text-gray-900">Authorization: Bearer {{ tokenValue }}</span>
          <button type="button" class="shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-700" @click="copy('Authorization: Bearer ' + tokenValue, 'header')">
            {{ copiedKey === 'header' ? 'Copied' : 'Copy' }}
          </button>
        </dd>
      </div>
    </dl>

    <!-- Client picker + snippet -->
    <div class="mt-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label="MCP client" class="inline-flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1">
          <button
            v-for="client in clients"
            :key="client.key"
            type="button"
            role="tab"
            :aria-selected="client.key === activeKey"
            class="focus-ring rounded-md border border-transparent px-3 py-1.5 text-sm font-medium transition"
            :class="client.key === activeKey
              ? 'bg-white text-indigo-700 border border-gray-200'
              : 'text-gray-600 hover:text-gray-900'"
            @click="activeKey = client.key"
          >
            {{ client.name }}
          </button>
        </div>
        <span class="font-mono text-xs text-gray-500">{{ active.where }}</span>
      </div>

      <p class="mt-3 text-sm text-gray-500">{{ active.hint }}</p>

      <div class="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xs">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-2">
          <span class="text-[11px] font-bold uppercase tracking-widest text-slate-500">{{ active.langLabel }}</span>
          <button
            type="button"
            class="rounded-md px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-200 hover:text-slate-900"
            @click="copy(active.code, 'snippet')"
          >
            {{ copiedKey === 'snippet' ? 'Copied' : 'Copy' }}
          </button>
        </div>
        <pre class="overflow-x-auto px-4 py-4 text-xs leading-relaxed text-slate-800"><code ref="codeEl" :class="`language-${active.highlight}`" v-text="active.code"></code></pre>
      </div>
    </div>

    <p class="mt-4 text-sm text-gray-500">
      Tip: previews are watermarked. Have the assistant preview a snap before it
      creates the final image. Test the connection locally with
      <span class="font-mono">php artisan mcp:inspector mcp</span>.
    </p>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'

const props = defineProps({
  url: { type: String, required: true },
})

const PLACEHOLDER = '<your-token>'

const token = ref('')
const reveal = ref(false)
const activeKey = ref('claude-code')
const copiedKey = ref('')
const codeEl = ref(null)
const tokenFieldId = `mcp-token-${Math.round(performance.now())}`

const tokenValue = computed(() => token.value || PLACEHOLDER)

const clients = computed(() => [
  {
    key: 'claude-code',
    name: 'Claude Code',
    where: 'CLI · run once',
    hint: 'Add the server with one command, then restart the session.',
    langLabel: 'Terminal',
    highlight: 'plaintext',
    code: `claude mcp add --transport http snapsbrew ${props.url} \\
  --header "Authorization: Bearer ${tokenValue.value}"`,
  },
  {
    key: 'cursor',
    name: 'Cursor',
    where: '~/.cursor/mcp.json',
    hint: 'Add to your global or project mcp.json, then reload.',
    langLabel: 'JSON',
    highlight: 'json',
    code: `{
  "mcpServers": {
    "snapsbrew": {
      "url": "${props.url}",
      "headers": {
        "Authorization": "Bearer ${tokenValue.value}"
      }
    }
  }
}`,
  },
  {
    key: 'codex',
    name: 'Codex CLI',
    where: '~/.codex/config.toml',
    hint: 'Add a streamable-HTTP server block.',
    langLabel: 'TOML',
    highlight: 'plaintext',
    code: `[mcp_servers.snapsbrew]
url = "${props.url}"

[mcp_servers.snapsbrew.http_headers]
Authorization = "Bearer ${tokenValue.value}"`,
  },
  {
    key: 'desktop',
    name: 'Claude Desktop',
    where: 'via mcp-remote',
    hint: 'Clients that only speak stdio bridge to the remote server with mcp-remote (needs Node).',
    langLabel: 'JSON',
    highlight: 'json',
    code: `{
  "mcpServers": {
    "snapsbrew": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "${props.url}",
        "--header",
        "Authorization: Bearer ${tokenValue.value}"
      ]
    }
  }
}`,
  },
])

const active = computed(() => clients.value.find(c => c.key === activeKey.value) || clients.value[0])

const highlight = () => {
  nextTick(() => {
    const el = codeEl.value
    if (!el || !window.hljs) return
    el.classList.remove('hljs')
    if (el.dataset) delete el.dataset.highlighted
    if (window.hljs.getLanguage && window.hljs.getLanguage(active.value.highlight)) {
      window.hljs.highlightElement(el)
    }
  })
}

const copy = (text, key) => {
  const done = () => {
    copiedKey.value = key
    setTimeout(() => { if (copiedKey.value === key) copiedKey.value = '' }, 2000)
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done))
  } else {
    fallbackCopy(text, done)
  }
}

const fallbackCopy = (text, done) => {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.focus()
  ta.select()
  try { document.execCommand('copy') } catch (e) { console.error('Copy failed', e) }
  document.body.removeChild(ta)
  done()
}

watch(active, highlight)
onMounted(highlight)
</script>
