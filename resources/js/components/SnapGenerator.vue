<template>
  <div class="grid gap-8 md:grid-cols-2">

    <!-- Customize -->
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
      <h2 class="text-lg font-semibold text-gray-900">Customize your snap</h2>
      <p class="mt-1 text-sm text-gray-500">Edit the text, then generate.</p>

      <div class="mt-5 space-y-4">
        <div v-for="(value, key) in form" :key="key">
          <label :for="`f-${key}`" class="block text-sm font-medium text-gray-700">{{ humanize(key) }}</label>
          <input
            :id="`f-${key}`"
            v-model="form[key]"
            type="text"
            maxlength="280"
            :disabled="state === 'generating'"
            class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-rose-500 focus:outline-hidden focus:ring-1 focus:ring-rose-500 disabled:bg-gray-50"
          >
        </div>
        <p v-if="!hasFields" class="text-sm text-gray-500">This template has no editable text — generate it as-is.</p>
      </div>

      <p v-if="errorMsg" class="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ errorMsg }}</p>

      <div class="mt-6 flex flex-wrap items-center gap-3">
        <button
          type="button"
          :disabled="state === 'generating'"
          @click="generate"
          class="inline-flex items-center gap-2 rounded-md bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-rose-500 focus:outline-hidden focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 disabled:opacity-60"
        >
          {{ generateLabel }}
        </button>
        <a
          v-if="state === 'timeout'"
          href="/snapshots"
          class="text-sm font-semibold text-rose-600 hover:text-rose-500"
        >Check snaps &rarr;</a>
        <a
          v-if="resultImage"
          :href="resultImage"
          target="_blank"
          rel="noopener"
          class="text-sm font-semibold text-rose-600 hover:text-rose-500"
        >Open image</a>
      </div>
    </div>

    <!-- Result -->
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
      <h2 class="text-lg font-semibold text-gray-900">Result</h2>

      <div class="mt-5 flex min-h-[260px] items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4" aria-live="polite" :aria-busy="state === 'generating'">
        <img v-if="resultImage" :src="resultImage" alt="Generated snap" class="max-h-[420px] w-full rounded-sm object-contain">
        <div v-else-if="state === 'generating'" class="text-center">
          <div class="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-rose-200 border-t-rose-600" aria-hidden="true"></div>
          <p class="mt-3 text-sm text-gray-500">Rendering — this takes a few seconds.</p>
        </div>
        <img v-else :src="previewImage" alt="Template preview" class="max-h-[420px] w-full rounded-sm object-contain opacity-60">
      </div>
    </div>

    <!-- Welcome dialog after the first snap of the company. UiModal gives the focus trap and the Esc key. -->
    <UiModal
      :open="showModal"
      title="That is your first snap."
      description="You made one by hand. Snapsbrew can make thousands from your templates: call the API, or connect it to your tools."
      @close="showModal = false"
    >
      <div class="grid gap-3 sm:grid-cols-3">
        <a href="/documentation/snaps" class="focus-ring rounded-lg border border-gray-200 p-3 text-center text-sm font-semibold text-gray-800 transition hover:border-rose-300 hover:bg-rose-50">
          Snaps API
        </a>
        <a href="/documentation" class="focus-ring rounded-lg border border-gray-200 p-3 text-center text-sm font-semibold text-gray-800 transition hover:border-rose-300 hover:bg-rose-50">
          All endpoints
        </a>
        <a href="/templates" class="focus-ring rounded-lg border border-gray-200 p-3 text-center text-sm font-semibold text-gray-800 transition hover:border-rose-300 hover:bg-rose-50">
          Your templates
        </a>
      </div>

      <template #footer>
        <UiButton variant="ghost" @click="showModal = false">Stay here</UiButton>
        <UiButton href="/dashboard">Go to dashboard</UiButton>
      </template>
    </UiModal>

  </div>
</template>

<script>
import UiButton from './ui/UiButton.vue'
import UiModal from './ui/UiModal.vue'

export default {
  name: 'SnapGenerator',

  components: { UiButton, UiModal },

  props: {
    storeUrl: { type: String, required: true },
    previewImage: { type: String, default: '' },
    fields: { type: Object, default: () => ({}) },
    // True while the company has no snap yet. Only the first snap opens the welcome dialog.
    firstSnap: { type: Boolean, default: false },
  },

  // Cap polling at MAX_POLLS attempts (~MAX_POLLS * POLL_INTERVAL_MS ms).
  // 60 * 2500ms = 2.5 min, enough headroom for a worker spike without
  // pegging the tab forever on a hung job.
  data() {
    return {
      form: { ...this.fields },
      state: 'editing', // editing | generating | done | error | timeout
      resultImage: null,
      errorMsg: null,
      showModal: false,
      pollTimer: null,
      pollAttempts: 0,
      MAX_POLLS: 60,
      POLL_INTERVAL_MS: 2500,
    }
  },

  computed: {
    hasFields() {
      return Object.keys(this.form).length > 0
    },
    generateLabel() {
      if (this.state === 'generating') return 'Brewing your snap…'
      if (this.state === 'error' || this.state === 'timeout') return 'Try again'
      return 'Generate snap'
    },
  },

  beforeUnmount() {
    this.stopPolling()
  },

  methods: {
    humanize(key) {
      return String(key).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    },

    stopPolling() {
      if (this.pollTimer) {
        clearTimeout(this.pollTimer)
        this.pollTimer = null
      }
    },

    async generate() {
      if (this.state === 'generating') return

      this.stopPolling()
      this.state = 'generating'
      this.errorMsg = null
      this.resultImage = null
      this.pollAttempts = 0

      try {
        const { data } = await window.axios.post(this.storeUrl, { fields: this.form })
        this.poll(data.status_url)
      } catch (e) {
        this.fail(e.response?.data?.message || 'Could not start generation. Please try again.')
      }
    },

    poll(statusUrl) {
      this.pollTimer = setTimeout(async () => {
        try {
          const { data } = await window.axios.get(statusUrl)

          if (data.status === 'completed' && data.image_url) {
            this.resultImage = data.image_url
            this.state = 'done'
            this.showModal = this.firstSnap
            return
          }

          if (data.status === 'failed' || data.status === 'canceled') {
            this.fail('Generation failed. Please try again.')
            return
          }

          this.pollAttempts += 1
          if (this.pollAttempts >= this.MAX_POLLS) {
            this.timeout()
            return
          }

          this.poll(statusUrl)
        } catch (e) {
          this.fail('Lost track of the snap. Refresh to check its status.')
        }
      }, this.POLL_INTERVAL_MS)
    },

    fail(message) {
      this.errorMsg = message
      this.state = 'error'
    },

    timeout() {
      this.errorMsg = 'Still brewing — your snap is taking longer than expected. It will keep rendering in the background.'
      this.state = 'timeout'
    },
  },
}
</script>
