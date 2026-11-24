<template>
  <div class="not-prose w-full overflow-hidden rounded-lg border border-slate-200 shadow-lg bg-white text-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2">
      <div class="flex flex-wrap items-center gap-3">
        <span class="text-[11px] font-bold tracking-widest text-slate-900 uppercase">{{ labelText }}</span>
        <div v-if="hasMultiple">
          <label class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-700">
            <select
              :value="activeLanguage"
              @change="setLanguage($event.target.value)"
              class="rounded-md border border-slate-400 w-48 bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-slate-800 focus:outline-hidden focus:ring-2 focus:ring-slate-500"
            >
              <option
                v-for="sample in normalisedSamples"
                :key="sample.key"
                :value="sample.key"
              >
                {{ sample.label }}
              </option>
            </select>
          </label>
        </div>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-1 rounded-md px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-700 hover:text-slate-900 transition hover:bg-slate-200"
        :disabled="copying || !activeSample"
        @click="copyCode"
      >
        {{ copying ? 'Copied' : 'Copy' }}
      </button>
    </div>
    <div class="overflow-auto max-h-[800px]">
      <pre v-if="activeSample" class="px-4 py-5 text-sm leading-6">
        <code
          :class="`language-${activeSample.highlight}`"
          ref="codeElement"
          v-text="activeSample.code"
        ></code>
      </pre>
      <pre v-else class="px-4 py-5 text-sm leading-6 text-slate-400">No snippet available.</pre>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'

const props = defineProps({
  label: {
    type: String,
    default: 'Code'
  },
  snippets: {
    type: Object,
    default: () => ({}),
  },
  defaultLanguage: {
    type: String,
    default: ''
  }
})

const LANGUAGE_MAP = {
  js: { label: 'JavaScript', highlight: 'javascript' },
  javascript: { label: 'JavaScript', highlight: 'javascript' },
  ts: { label: 'TypeScript', highlight: 'typescript' },
  typescript: { label: 'TypeScript', highlight: 'typescript' },
  py: { label: 'Python', highlight: 'python' },
  python: { label: 'Python', highlight: 'python' },
  php: { label: 'PHP', highlight: 'php' },
  rb: { label: 'Rails', highlight: 'ruby' },
  ruby: { label: 'Rails', highlight: 'ruby' },
  rails: { label: 'Rails', highlight: 'ruby' },
  json: { label: 'JSON', highlight: 'json' },
  http: { label: 'HTTP', highlight: 'http' },
}

const codeElement = ref(null)
const activeLanguage = ref('')
const copying = ref(false)

const normalisedSamples = computed(() => {
  const entries = Object.entries(props.snippets || {})
  return entries.map(([key, value]) => {
    const descriptor = typeof value === 'object' && value !== null && Object.prototype.hasOwnProperty.call(value, 'code')
      ? value
      : { code: value }

    const baseKey = descriptor.key || key
    const lookup = LANGUAGE_MAP[baseKey.toLowerCase()] || {}
    const label = descriptor.label || lookup.label || baseKey.toUpperCase()
    const highlight = descriptor.language || lookup.highlight || baseKey.toLowerCase()
    const code = (descriptor.code ?? '').toString().trim()

    return {
      key: baseKey,
      label,
      highlight,
      code,
    }
  }).filter(sample => sample.code.length > 0)
})

const hasMultiple = computed(() => normalisedSamples.value.length > 1)
const labelText = computed(() => props.label || 'Code')

const activeSample = computed(() => {
  const list = normalisedSamples.value
  if (!list.length) {
    return null
  }
  return list.find(sample => sample.key === activeLanguage.value) || list[0]
})

const highlightActive = () => {
  nextTick(() => {
    if (codeElement.value && window.hljs && typeof window.hljs.highlightElement === 'function') {
      codeElement.value.classList.remove('hljs')
      if (codeElement.value.dataset && codeElement.value.dataset.highlighted) {
        delete codeElement.value.dataset.highlighted
      }
      window.hljs.highlightElement(codeElement.value)
    }
  })
}

const selectInitialLanguage = () => {
  const list = normalisedSamples.value
  if (!list.length) {
    activeLanguage.value = ''
    return
  }

  if (props.defaultLanguage) {
    const candidate = list.find(sample => sample.key === props.defaultLanguage)
    if (candidate) {
      activeLanguage.value = candidate.key
      return
    }
  }

  activeLanguage.value = list[0].key
}

const setLanguage = (languageKey) => {
  if (languageKey === activeLanguage.value) {
    return
  }
  const exists = normalisedSamples.value.some(sample => sample.key === languageKey)
  if (exists) {
    activeLanguage.value = languageKey
  }
}

const copyCode = () => {
  if (!activeSample.value) {
    return
  }

  const text = activeSample.value.code

  const fallbackCopy = () => {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.top = '-1000px'
    textarea.style.left = '-1000px'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    try {
      document.execCommand('copy')
    } catch (error) {
      console.error('Copy failed', error)
    }
    document.body.removeChild(textarea)
  }

  const indicate = () => {
    copying.value = true
    setTimeout(() => {
      copying.value = false
    }, 2000)
  }

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(indicate).catch(() => {
      fallbackCopy()
      indicate()
    })
  } else {
    fallbackCopy()
    indicate()
  }
}

watch(() => props.snippets, () => {
  selectInitialLanguage()
}, { immediate: true, deep: true })

watch(activeSample, () => {
  highlightActive()
})

watch(() => props.defaultLanguage, () => {
  selectInitialLanguage()
})

onMounted(() => {
  selectInitialLanguage()
  highlightActive()
})
</script>
