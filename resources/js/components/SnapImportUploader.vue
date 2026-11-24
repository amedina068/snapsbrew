<template>
  <div class="space-y-5">
    <div v-if="collectMetadata" class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="block text-sm font-semibold text-gray-800" for="snap-import-title">Import title</label>
        <input
          id="snap-import-title"
          type="text"
          class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-500 focus:ring-0"
          placeholder="July influencer batch"
          v-model="title"
          @input="clearFieldError('title')"
        >
        <p v-if="fieldErrors.title" class="mt-1 text-sm text-red-700">{{ fieldErrors.title }}</p>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-800" for="snap-import-webhook">
          Webhook URL <span class="font-normal text-gray-500">(optional)</span>
        </label>
        <input
          id="snap-import-webhook"
          type="text"
          aria-describedby="snap-import-webhook-help"
          class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-500 focus:ring-0"
          placeholder="https://example.com/webhooks/snaps"
          v-model="webhookUrl"
          @input="clearFieldError('webhook_url')"
        >
        <p id="snap-import-webhook-help" class="mt-1 text-xs text-gray-500">We will POST updates when each snap completes and once the import finishes.</p>
        <p v-if="fieldErrors.webhook_url" class="mt-1 text-sm text-red-700">{{ fieldErrors.webhook_url }}</p>
      </div>
    </div>

    <div>
      <div
        role="button"
        tabindex="0"
        aria-label="Choose a spreadsheet to upload"
        class="focus-ring relative flex flex-col items-center gap-3 rounded-xl px-6 py-10 text-center ring-2 transition-colors sm:flex-row sm:text-left"
        :class="[
          isDragging ? 'bg-rose-50 ring-rose-400' : 'bg-slate-50 ring-slate-200 hover:ring-slate-300',
          isUploading ? 'pointer-events-none opacity-60' : 'cursor-pointer',
        ]"
        @dragenter.prevent="handleDragEnter"
        @dragover.prevent="handleDragEnter"
        @dragleave.prevent="handleDragLeave"
        @drop.prevent="handleDrop"
        @click="triggerFilePicker"
        @keydown.enter.prevent="triggerFilePicker"
        @keydown.space.prevent="triggerFilePicker"
      >
        <span class="grid size-12 shrink-0 place-items-center rounded-full bg-white text-rose-600 ring-1 ring-gray-200">
          <CloudUploadIcon class="size-6" aria-hidden="true" />
        </span>
        <span>
          <span class="block text-sm font-semibold text-gray-900">Drop your file here, or click to browse</span>
          <span class="mt-0.5 block text-xs text-gray-500">Only .xlsx, .xls, or .csv files.</span>
        </span>
        <span v-if="isUploading" class="absolute inset-0 grid place-items-center rounded-xl bg-white/70" role="status">
          <span class="size-6 animate-spin rounded-full border-2 border-rose-200 border-t-rose-600" aria-hidden="true"></span>
          <span class="sr-only">Uploading</span>
        </span>
      </div>
      <!-- Outside the drop zone: a control inside a role="button" element is a nested control. -->
      <input
        ref="fileInput"
        type="file"
        class="sr-only"
        tabindex="-1"
        aria-hidden="true"
        accept=".xlsx,.xls,.csv"
        @change="handleFileChange"
      >
      <p v-if="fieldErrors.file" class="mt-2 text-sm text-red-700">{{ fieldErrors.file }}</p>
    </div>

    <div class="flex justify-end">
      <UiButton @click="triggerFilePicker">Upload spreadsheet</UiButton>
    </div>

    <p v-if="errorMessage" class="text-sm text-red-700" role="alert">{{ errorMessage }}</p>
    <p v-if="successMessage" class="text-sm text-emerald-700" role="status">{{ successMessage }}</p>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { CloudUploadIcon } from '@heroicons/vue/outline'
import UiButton from './ui/UiButton.vue'

const props = defineProps({
  uploadUrl: {
    type: String,
    required: true
  },
  collectMetadata: {
    type: Boolean,
    default: false
  }
})

const fileInput = ref(null)
const isDragging = ref(false)
const isUploading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const title = ref('')
const webhookUrl = ref('')
const fieldErrors = reactive({
  title: '',
  webhook_url: '',
  file: ''
})

const resetFieldErrors = () => {
  fieldErrors.title = ''
  fieldErrors.webhook_url = ''
  fieldErrors.file = ''
}

const resetMessages = () => {
  errorMessage.value = ''
  successMessage.value = ''
  resetFieldErrors()
}

const clearFieldError = field => {
  if (fieldErrors[field]) {
    fieldErrors[field] = ''
  }
}

const handleDragEnter = () => {
  if (isUploading.value) {
    return
  }
  isDragging.value = true
}

const handleDragLeave = () => {
  isDragging.value = false
}

const handleDrop = event => {
  if (isUploading.value) {
    return
  }

  isDragging.value = false
  const [file] = event.dataTransfer.files || []
  if (!file) {
    return
  }
  uploadFile(file)
}

const handleFileChange = event => {
  const [file] = event.target.files || []
  if (!file) {
    return
  }
  uploadFile(file)
  event.target.value = ''
}

const triggerFilePicker = () => {
  if (isUploading.value) {
    return
  }
  fileInput.value?.click()
}

const applyServerErrors = errors => {
  if (!errors) {
    return
  }
  fieldErrors.title = errors.title?.[0] || ''
  fieldErrors.webhook_url = errors.webhook_url?.[0] || ''
  fieldErrors.file = errors.file?.[0] || ''
}

const uploadFile = file => {
  resetMessages()

  if (props.collectMetadata && !title.value.trim()) {
    fieldErrors.title = 'Please add a title for this import.'
    return
  }

  // Drag and drop never consults the `accept` attribute, so this guard is the
  // client-side authority. Keep the list in step with the `extensions` rule in
  // SnapImportController::store(). The message repeats that rule's message word
  // for word, so a client rejection and a server rejection never disagree.
  const filename = file.name.toLowerCase()
  const allowedExtensions = ['.xlsx', '.xls', '.csv']
  if (!allowedExtensions.some(extension => filename.endsWith(extension))) {
    const message = 'The file name must end in .xlsx, .xls, or .csv. Rename the file or choose a different one.'
    fieldErrors.file = message
    errorMessage.value = message
    return
  }

  const formData = new FormData()

  if (props.collectMetadata) {
    formData.append('title', title.value.trim())

    const trimmedWebhook = webhookUrl.value.trim()
    if (trimmedWebhook.length) {
      formData.append('webhook_url', trimmedWebhook)
    }
  }

  formData.append('file', file)

  isUploading.value = true

  axios.post(props.uploadUrl, formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  }).then(response => {
    successMessage.value = 'Import started. Track the progress below.'
    window.emitter.emit('snap-import:started', response.data)
  }).catch(error => {
    const errors = error.response?.data?.errors
    applyServerErrors(errors)

    if (errors) {
      const [firstKey] = Object.keys(errors)
      if (firstKey && errors[firstKey]?.length) {
        errorMessage.value = errors[firstKey][0]
        return
      }
    }

    if (error.response?.data?.message) {
      errorMessage.value = error.response.data.message
    } else {
      errorMessage.value = 'We could not start the import. Please try again.'
    }
  }).finally(() => {
    isUploading.value = false
  })
}
</script>
