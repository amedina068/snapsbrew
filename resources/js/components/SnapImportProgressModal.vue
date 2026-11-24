<template>
  <UiModal
    :open="isOpen"
    size="lg"
    title="Import progress"
    description="Track the snaps being generated from your spreadsheet."
    @close="closeModal"
  >
    <div v-if="importData" class="space-y-5">
      <div>
        <div class="flex items-baseline justify-between text-sm">
          <span class="font-semibold text-gray-800">Progress</span>
          <span class="tabular-nums text-gray-600">{{ processedRows }} / {{ importData.total_rows }}</span>
        </div>
        <div
          class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100"
          role="progressbar"
          aria-label="Import progress"
          aria-valuemin="0"
          :aria-valuemax="importData.total_rows"
          :aria-valuenow="processedRows"
        >
          <div class="h-full rounded-full bg-rose-600 transition-all" :style="{ width: progressBarWidth }"></div>
        </div>
      </div>

      <ul class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <li v-for="counter in counters" :key="counter.label" class="rounded-lg bg-slate-50 px-3 py-2">
          <span class="block text-xs text-gray-500">{{ counter.label }}</span>
          <span class="text-lg font-semibold tabular-nums text-gray-900">{{ counter.value }}</span>
        </li>
      </ul>

      <p class="text-sm text-gray-600" aria-live="polite">
        Current status: <span class="font-semibold capitalize text-gray-900">{{ importData.status }}</span>
      </p>
    </div>

    <p v-if="errorMessage" class="mt-3 text-sm text-red-700" role="alert">{{ errorMessage }}</p>

    <p class="mt-4 text-xs text-gray-500">
      It is safe to close this window. You will get an email when the import is done. Last updated {{ lastUpdatedText }}.
    </p>

    <template #footer>
      <UiButton variant="ghost" @click="closeModal">Close</UiButton>
      <UiButton v-if="canCancel" variant="danger" :disabled="isCancelling" @click="cancelImport">
        {{ isCancelling ? 'Canceling…' : 'Cancel import' }}
      </UiButton>
      <UiButton v-if="redirectUrl" :href="redirectUrl">View import</UiButton>
    </template>
  </UiModal>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import UiButton from './ui/UiButton.vue'
import UiModal from './ui/UiModal.vue'

const isOpen = ref(false)
const importData = ref(null)
const statusUrl = ref('')
const cancelUrl = ref('')
const redirectUrl = ref('')
const pollingTimer = ref(null)
const isCancelling = ref(false)
const errorMessage = ref('')
const lastUpdated = ref(null)

const processedRows = computed(() => {
  if (!importData.value) {
    return 0
  }

  if (importData.value.processed_rows !== undefined) {
    return importData.value.processed_rows
  }

  return (importData.value.completed || 0) +
    (importData.value.failed || 0) +
    (importData.value.canceled || 0)
})

const progressBarWidth = computed(() => {
  if (!importData.value || !importData.value.total_rows) {
    return '0%'
  }
  const progress = Math.min(100, Math.round((processedRows.value / importData.value.total_rows) * 100))
  return `${progress}%`
})

const counters = computed(() => [
  { label: 'Completed', value: importData.value?.completed ?? 0 },
  { label: 'Pending', value: importData.value?.pending ?? 0 },
  { label: 'Failed', value: importData.value?.failed ?? 0 },
  { label: 'Canceled', value: importData.value?.canceled ?? 0 },
])

const canCancel = computed(() => {
  if (!importData.value) {
    return false
  }
  return ['processing', 'pending'].includes(importData.value.status)
})

const lastUpdatedText = computed(() => {
  if (!lastUpdated.value) {
    return 'just now'
  }
  const delta = (Date.now() - lastUpdated.value) / 1000
  if (delta < 5) {
    return 'just now'
  }
  if (delta < 60) {
    return `${Math.round(delta)}s ago`
  }
  const minutes = Math.round(delta / 60)
  return `${minutes}m ago`
})

const openModal = payload => {
  importData.value = payload.import
  statusUrl.value = payload.status_url
  cancelUrl.value = payload.cancel_url
  redirectUrl.value = payload.redirect_url
  errorMessage.value = ''
  lastUpdated.value = Date.now()
  isOpen.value = true
  startPolling()
}

const updateImport = data => {
  importData.value = data.import
  lastUpdated.value = Date.now()

  if (['completed', 'failed', 'canceled'].includes(importData.value.status)) {
    stopPolling()
  }
}

const startPolling = () => {
  stopPolling()
  if (!statusUrl.value) {
    return
  }

  fetchStatus()
  pollingTimer.value = setInterval(fetchStatus, 3000)
}

const stopPolling = () => {
  if (pollingTimer.value) {
    clearInterval(pollingTimer.value)
    pollingTimer.value = null
  }
}

const fetchStatus = () => {
  if (!statusUrl.value) {
    return
  }

  axios.get(statusUrl.value)
    .then(response => {
      updateImport(response.data)
    })
    .catch(() => {
      errorMessage.value = 'Unable to refresh the import status right now.'
      stopPolling()
    })
}

const cancelImport = () => {
  if (!cancelUrl.value || isCancelling.value) {
    return
  }

  isCancelling.value = true
  axios.post(cancelUrl.value)
    .then(response => {
      updateImport(response.data)
    })
    .catch(error => {
      if (error.response?.data?.message) {
        errorMessage.value = error.response.data.message
      } else {
        errorMessage.value = 'We could not cancel the import. Please try again.'
      }
    })
    .finally(() => {
      isCancelling.value = false
    })
}

const closeModal = () => {
  stopPolling()
  isOpen.value = false
  importData.value = null
  statusUrl.value = ''
  cancelUrl.value = ''
  redirectUrl.value = ''
}

onMounted(() => {
  window.emitter.on('snap-import:started', openModal)
  window.emitter.on('snap-import:monitor', openModal)
})

onBeforeUnmount(() => {
  window.emitter.off('snap-import:started', openModal)
  window.emitter.off('snap-import:monitor', openModal)
  stopPolling()
})
</script>
