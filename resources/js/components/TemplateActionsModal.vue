<template>
  <UiModal
    :open="isOpen"
    :title="template?.title ?? 'Template'"
    :description="template?.readableSize ? `Template actions · Size: ${template.readableSize}` : 'Template actions'"
    @close="close"
  >
    <ul v-if="mode === 'menu'" class="space-y-2">
      <li>
        <form action="/templates" method="POST">
          <input type="hidden" name="_token" :value="csrfToken">
          <input type="hidden" name="template_id" :value="template.id">
          <button type="submit" :class="actionClass">
            <span :class="iconClass"><DuplicateIcon class="size-5" aria-hidden="true" /></span>
            <span>
              <span class="block text-sm font-semibold text-gray-900">Duplicate template</span>
              <span class="block text-xs text-gray-500">Create an editable copy with the same content and tags.</span>
            </span>
          </button>
        </form>
      </li>
      <li>
        <button type="button" :class="actionClass" @click="openRename">
          <span :class="iconClass"><PencilIcon class="size-5" aria-hidden="true" /></span>
          <span>
            <span class="block text-sm font-semibold text-gray-900">Rename template</span>
            <span class="block text-xs text-gray-500">Update the title used throughout your workspace.</span>
          </span>
        </button>
      </li>
      <li>
        <button type="button" :class="actionClass" @click="openTags">
          <span :class="iconClass"><TagIcon class="size-5" aria-hidden="true" /></span>
          <span>
            <span class="block text-sm font-semibold text-gray-900">Manage tags</span>
            <span class="block text-xs text-gray-500">Organize templates with reusable tags and quick filters.</span>
          </span>
        </button>
      </li>
    </ul>

    <div v-else-if="mode === 'rename'">
      <label class="block text-sm font-semibold text-gray-800" for="rename-title">New title</label>
      <input
        id="rename-title"
        type="text"
        v-model="renameTitle"
        aria-describedby="rename-title-help"
        class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-900 focus:border-gray-500 focus:ring-0"
        placeholder="Enter template title"
        @keydown.enter.prevent="submitRename"
      >
      <p id="rename-title-help" class="mt-1 text-xs text-gray-500">This name appears in search results, filters, and collection lists.</p>
    </div>

    <div v-else-if="mode === 'tags'">
      <p class="text-sm font-semibold text-gray-800">Tags</p>
      <p class="mb-3 text-xs text-gray-500">Search existing tags or create new ones to keep templates organized.</p>
      <tag-picker v-model="selectedTags" />
    </div>

    <p v-if="errorMessage" class="mt-4 text-sm text-red-700" role="alert">{{ errorMessage }}</p>

    <template v-if="mode !== 'menu'" #footer>
      <UiButton variant="ghost" @click="showMenu">Back</UiButton>
      <UiButton :disabled="isProcessing" @click="mode === 'rename' ? submitRename() : saveTags()">
        <span v-if="isProcessing" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
        {{ mode === 'rename' ? 'Save' : 'Save tags' }}
      </UiButton>
    </template>
  </UiModal>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { DuplicateIcon, PencilIcon, TagIcon } from '@heroicons/vue/outline'
import TagPicker from './TagPicker'
import UiButton from './ui/UiButton.vue'
import UiModal from './ui/UiModal.vue'

defineOptions({ name: 'TemplateActionsModal' })

const isOpen = ref(false)
const mode = ref('menu')
const template = ref(null)
const renameTitle = ref('')
const selectedTags = ref([])
const isProcessing = ref(false)
const errorMessage = ref(null)

const actionClass = 'focus-ring flex w-full items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-3 text-left transition-colors hover:border-rose-300 hover:bg-rose-50/50'
const iconClass = 'grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-gray-700'

const csrfToken = computed(() => document.querySelector('meta[name=csrf-token]')?.content ?? '')

const handleOpen = payload => open(payload)
const handleClose = () => close()

onMounted(() => {
  window.emitter.on('open-template-actions', handleOpen)
  window.emitter.on('close-template-actions', handleClose)
})

onBeforeUnmount(() => {
  window.emitter.off('open-template-actions', handleOpen)
  window.emitter.off('close-template-actions', handleClose)
  document.body.classList.remove('overflow-hidden')
})

function open(payload) {
  template.value = payload
  renameTitle.value = payload?.title ?? ''
  selectedTags.value = payload?.tags ? [...payload.tags] : []
  errorMessage.value = null
  mode.value = 'menu'
  isOpen.value = true
  document.body.classList.add('overflow-hidden')
}

function close() {
  isOpen.value = false
  isProcessing.value = false
  errorMessage.value = null
  document.body.classList.remove('overflow-hidden')
}

function showMenu() {
  mode.value = 'menu'
  errorMessage.value = null
}

function openRename() {
  mode.value = 'rename'
  errorMessage.value = null
  renameTitle.value = template.value?.title ?? ''
}

function openTags() {
  mode.value = 'tags'
  errorMessage.value = null
}

function submitRename() {
  if (!template.value) return
  if (!renameTitle.value.trim()) {
    errorMessage.value = 'Please enter a title.'
    return
  }
  isProcessing.value = true
  errorMessage.value = null

  window.axios
    .patch(`/api/v1/templates/${template.value.uuid}/rename`, {
      title: renameTitle.value.trim()
    })
    .then(() => {
      window.location.reload()
    })
    .catch(error => {
      errorMessage.value = extractErrorMessage(error, 'Unable to rename template.')
      isProcessing.value = false
    })
}

function saveTags() {
  if (!template.value) return
  isProcessing.value = true
  errorMessage.value = null

  const tagIds = selectedTags.value.map(tag => tag.id)

  window.axios
    .post(`/api/v1/templates/${template.value.uuid}/tags`, {
      tag_ids: tagIds
    })
    .then(() => {
      window.location.reload()
    })
    .catch(error => {
      errorMessage.value = extractErrorMessage(error, 'Unable to update tags.')
      isProcessing.value = false
    })
}

function extractErrorMessage(error, fallback) {
  if (error.response && error.response.data) {
    if (typeof error.response.data.message === 'string') {
      return error.response.data.message
    }

    if (error.response.data.errors) {
      const firstKey = Object.keys(error.response.data.errors)[0]
      if (firstKey) {
        return error.response.data.errors[firstKey][0]
      }
    }
  }

  return fallback
}
</script>
