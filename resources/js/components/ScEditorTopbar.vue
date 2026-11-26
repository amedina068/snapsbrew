<template>
  <div class="bg-white h-16 border-b flex items-center justify-between px-4">
    <div class="flex items-center space-x-4">
      <a
        class="pl-2 pr-4 border-r inline-flex items-center space-x-2 text-gray-600"
        :href="backHref"
        @click="onNavigate($event, backHref)"
      >
        <ArrowLongLeftIcon class="h-5 w-5 text-gray-400" />
        <span>Back to template</span>
      </a>
      <AddLayerActions />
      <template-title-inline-editor
        :model-value="layoutTitle"
        @update:modelValue="updateTitle"
      />
    </div>

    <div class="flex items-center space-x-4">
      <button
        type="button"
        class="inline-flex items-center justify-center rounded-md border border-gray-200 bg-white p-2 text-gray-500 shadow-xs transition hover:border-indigo-200 hover:text-indigo-600 focus:border-indigo-300 focus:text-indigo-600 focus:outline-hidden focus:ring-2 focus:ring-indigo-200"
        @click="openSettings"
        aria-label="Template settings"
      >
        <Cog6ToothIcon class="h-5 w-5" />
      </button>
      <div class="flex items-center">
        <slot name="actions"></slot>
      </div>
    </div>

    <UnsavedChangesModal
      :open="showUnsavedModal"
      :busy="saving"
      @save="saveAndLeave"
      @leave="leaveWithoutSaving"
      @cancel="cancelLeave"
    />
  </div>
</template>

<script setup>
import AddLayerActions from './AddLayerActions.vue'
import UnsavedChangesModal from './UnsavedChangesModal.vue'
import { ArrowLongLeftIcon, Cog6ToothIcon } from '@heroicons/vue/24/outline'
import TemplateTitleInlineEditor from './TemplateTitleInlineEditor.vue'
import { computed, ref, onBeforeUnmount, onMounted } from 'vue'
import { useStore } from 'vuex'

const store = useStore()

const templateUuid = computed(() => store.state.templateUuid)
const layoutTitle = computed(() => store.state.layoutTitle)
const isDirty = computed(() => store.state.isDirty)

const backHref = computed(() => `/templates/${templateUuid.value}`)

const showUnsavedModal = ref(false)
const saving = ref(false)
const pendingHref = ref(null)

const updateTitle = newTitle => {
  store.commit('updateLayoutProperty', { property: 'layoutTitle', value: newTitle })
}

const openSettings = () => {
  window.emitter.emit('openTemplateSettings')
}

// Intercept in-app navigation: if there are unsaved changes, show the custom
// modal instead of letting the browser fire its native unload prompt.
const onNavigate = (event, href) => {
  if (!isDirty.value) {
    return
  }
  event.preventDefault()
  pendingHref.value = href
  showUnsavedModal.value = true
}

const navigateToPending = () => {
  store.commit('setDirty', false) // avoid the native beforeunload prompt on the way out
  window.location.href = pendingHref.value
}

const cancelLeave = () => {
  showUnsavedModal.value = false
  saving.value = false
  pendingHref.value = null
}

const leaveWithoutSaving = () => navigateToPending()

const saveAndLeave = () => {
  saving.value = true
  window.emitter.emit('editor:request-save')
}

const onSaved = () => {
  if (showUnsavedModal.value) {
    navigateToPending()
  }
}

const onSaveFailed = () => {
  saving.value = false
}

// Native beforeunload covers hard exits (refresh, tab close, external links)
// that we can't intercept — but only warn when there are actual changes.
const beforeUnloadHandler = event => {
  if (!isDirty.value) {
    return
  }
  event.preventDefault()
  event.returnValue = ''
}

onMounted(() => {
  window.addEventListener('beforeunload', beforeUnloadHandler)
  window.emitter.on('editor:saved', onSaved)
  window.emitter.on('editor:save-failed', onSaveFailed)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnloadHandler)
  window.emitter.off('editor:saved', onSaved)
  window.emitter.off('editor:save-failed', onSaveFailed)
})
</script>
