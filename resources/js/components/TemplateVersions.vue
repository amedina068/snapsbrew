<template>
  <div class="flex items-center gap-3">
    <!-- Status pill: Draft vs Live v# -->
    <span
      class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold"
      :class="publishedVersionId ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
    >
      <span class="h-1.5 w-1.5 rounded-full" :class="publishedVersionId ? 'bg-green-500' : 'bg-amber-500'"></span>
      <template v-if="publishedVersionId">
        Live v{{ currentVersionNumber }}<span v-if="currentPublishedAgo" class="font-normal opacity-70"> · published {{ currentPublishedAgo }}</span>
      </template>
      <template v-else>Draft — not published</template>
    </span>

    <p v-if="error" role="alert" class="text-xs font-medium text-red-600">{{ error }}</p>

    <button
      type="button"
      class="inline-flex items-center rounded-full bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 disabled:opacity-50"
      :disabled="publishing"
      @click="publish"
    >
      {{ publishing ? 'Publishing…' : 'Publish' }}
    </button>

    <button
      type="button"
      class="text-sm font-medium text-gray-500 hover:text-gray-700"
      @click="showHistory = !showHistory"
      :aria-expanded="showHistory.toString()"
    >
      History
    </button>

    <!-- History panel -->
    <div v-if="showHistory" class="absolute right-4 top-16 z-40 w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
      <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Version history</h3>
      <p v-if="!versions.length" class="py-4 text-center text-sm text-gray-400">
        No published versions yet. Publish to create your first snapshot.
      </p>
      <ul v-else class="max-h-72 space-y-1 overflow-y-auto">
        <li v-for="version in versions" :key="version.id" class="flex items-center justify-between rounded-sm px-2 py-1.5 text-sm hover:bg-gray-50">
          <span class="flex flex-col">
            <span class="font-medium text-gray-800">
              v{{ version.version_number }}
              <span v-if="version.is_current" class="ml-1 rounded-sm bg-green-100 px-1.5 text-[10px] font-semibold text-green-700">LIVE</span>
              <span v-else-if="!version.published" class="ml-1 rounded-sm bg-gray-100 px-1.5 text-[10px] font-semibold text-gray-500">draft snapshot</span>
            </span>
            <span class="text-xs text-gray-400">
              {{ formatDate(version.created_at) }}<span v-if="version.author"> · {{ version.author }}</span>
            </span>
          </span>
          <span class="flex items-center gap-2">
            <button
              type="button"
              class="text-gray-400 hover:text-indigo-600 disabled:opacity-40"
              :disabled="previewingId === version.id"
              :title="`Preview v${version.version_number}`"
              :aria-label="`Preview v${version.version_number}`"
              @click="preview(version)"
            >
              <!-- eye icon -->
              <EyeIcon class="h-4 w-4" v-if="previewingId !== version.id" aria-hidden="true" />
              <svg v-else class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4Z" />
              </svg>
            </button>
            <button
              v-if="!version.is_current"
              type="button"
              class="text-xs font-medium text-indigo-600 hover:text-indigo-800"
              @click="revert(version)"
            >
              Revert
            </button>
          </span>
        </li>
      </ul>
    </div>

    <!-- Version preview modal -->
    <div
      v-if="previewImage"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      @click.self="closePreview"
    >
      <div class="flex max-h-full w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
          <h3 class="text-sm font-semibold text-gray-800">Preview — v{{ previewVersionNumber }}</h3>
          <button type="button" class="text-gray-400 hover:text-gray-700" aria-label="Close preview" @click="closePreview">
            <XIcon class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
        <div class="flex items-center justify-center overflow-auto bg-gray-100 p-4">
          <img :src="previewImage" :alt="`Version ${previewVersionNumber} preview`" class="max-h-[70vh] max-w-full object-contain shadow-sm" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { EyeIcon, XIcon } from '@heroicons/vue/outline'
  import { ref, computed, onMounted, onUnmounted } from 'vue'
  import { useStore } from 'vuex'

  const store = useStore()
  const templateId = computed(() => store.state.templateId)

  const versions = ref([])
  const publishedVersionId = ref(null)
  const publishing = ref(false)
  const showHistory = ref(false)
  const error = ref('')

  // Version preview modal
  const previewingId = ref(null)
  const previewImage = ref('')
  const previewVersionNumber = ref('')

  const currentVersion = computed(() =>
    versions.value.find(version => version.id === publishedVersionId.value)
  )

  const currentVersionNumber = computed(() => currentVersion.value?.version_number ?? '')

  const currentPublishedAgo = computed(() => relativeTime(currentVersion.value?.created_at))

  const formatDate = value => {
    if (! value) {
      return ''
    }
    const date = new Date(value)
    return isNaN(date) ? '' : date.toLocaleString()
  }

  // Coarse "Xd ago" for the status pill — no date library on the page.
  const relativeTime = value => {
    if (! value) {
      return ''
    }
    const then = new Date(value)
    if (isNaN(then)) {
      return ''
    }
    const seconds = Math.max(0, Math.floor((Date.now() - then.getTime()) / 1000))
    const units = [['d', 86400], ['h', 3600], ['m', 60]]
    for (const [label, size] of units) {
      const n = Math.floor(seconds / size)
      if (n >= 1) {
        return `${n}${label} ago`
      }
    }
    return 'just now'
  }

  const load = () => {
    if (! templateId.value) {
      return
    }
    return axios.get(`/templates/${templateId.value}/versions`)
      .then(({ data }) => {
        versions.value = data.data
        publishedVersionId.value = data.published_version_id
      })
      .catch(() => error.value = 'Could not load version history.')
  }

  const publish = () => {
    if (! confirm('Publish this template? Live API renders will switch to this version; in-flight renders keep the previous one.')) {
      return
    }
    error.value = ''
    publishing.value = true
    axios.post(`/templates/${templateId.value}/publish`)
      .then(() => load())
      .catch(() => error.value = 'Publish failed. Please try again.')
      .finally(() => publishing.value = false)
  }

  const revert = version => {
    if (! confirm(`Revert the draft to v${version.version_number}? Your current draft is saved to history first, so you can undo this.`)) {
      return
    }
    error.value = ''
    axios.post(`/templates/${templateId.value}/versions/${version.id}/revert`)
      .then(() => {
        // The draft layers changed server-side; reload so the editor rehydrates
        // (re-running LayersImporter normalization on the reverted layers).
        window.location.reload()
      })
      .catch(() => error.value = 'Revert failed. Your draft was not changed.')
  }

  const preview = version => {
    if (previewingId.value) {
      return
    }
    error.value = ''
    previewVersionNumber.value = version.version_number

    // Already rendered once — just load the stored image, no request.
    if (version.preview_image) {
      previewImage.value = version.preview_image
      return
    }

    previewingId.value = version.id
    axios.get(`/templates/${templateId.value}/versions/${version.id}/preview`)
      .then(({ data }) => {
        version.preview_image = data.image
        previewImage.value = data.image
      })
      .catch(() => error.value = 'Could not render that version.')
      .finally(() => previewingId.value = null)
  }

  const closePreview = () => {
    previewImage.value = ''
    previewVersionNumber.value = ''
  }

  const onKeydown = event => {
    if (event.key !== 'Escape') {
      return
    }
    if (previewImage.value) {
      closePreview()
    } else if (showHistory.value) {
      showHistory.value = false
    }
  }

  onMounted(() => {
    load()
    window.addEventListener('keydown', onKeydown)
  })
  onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>
