<template>
  <div class="relative ml-3 flex items-center">
    <UiButton
      variant="secondary"
      :disabled="hasInvalidLayers || loading"
      :aria-disabled="hasInvalidLayers.toString()"
      :aria-busy="loading.toString()"
      :title="hasInvalidLayers ? 'Fix the highlighted layer before saving (a barcode value is invalid).' : null"
      @click.prevent="save"
    >
      <span v-if="loading" class="size-4 animate-spin rounded-full border-2 border-indigo-200 border-t-indigo-600" aria-hidden="true"></span>
      {{ loading ? 'Saving…' : 'Save' }}
    </UiButton>
    <p
      v-if="saveError"
      role="alert"
      data-test="template-save-error"
      class="absolute top-full right-0 z-30 mt-2 w-80 rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-medium text-red-700 shadow-lg"
    >
      {{ saveError }}
    </p>
  </div>
</template>

<script setup>
  import UiButton from './ui/UiButton.vue'
  import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
  import { useStore } from 'vuex'

  const store = useStore()
  const loading = ref(false)
  const saveError = ref(null)
  const layers = computed(() => store.state.layers)
  const groups = computed(() => store.state.groups)
  const hasInvalidLayers = computed(() => store.getters.hasInvalidLayers)
  const templateId = computed(() => store.state.templateId)
  const isOwner = computed(() => store.state.isOwner)
  const layoutWidth = computed(() => store.state.layoutWidth)
  const layoutTitle = computed(() => store.state.layoutTitle)
  const layoutHeight = computed(() => store.state.layoutHeight)

  const updateTemplate = (toggleLoading = true) => {
    // Never persist a template with invalid layer content (e.g. a barcode whose
    // value doesn't match its symbology) — it would render broken.
    if (hasInvalidLayers.value) {
      return Promise.reject(new Error('invalid-layers'))
    }
    if (toggleLoading) {
      loading.value = true
    }
    return axios.patch(`/api/v1/templates/${templateId.value}`, {
      title: layoutTitle.value,
      layers: layers.value,
      groups: groups.value,
      width: layoutWidth.value,
      height: layoutHeight.value,
    })
      .then(response => {
        store.commit('setDirty', false)
        saveError.value = null
        return response
      })
      .catch(error => {
        saveError.value = saveErrorMessage(error)
        throw error
      })
      .finally(() => toggleLoading ? loading.value = false : null)
  }

  // The API refuses a legacy save of a template that has a v2 editor document.
  // This editor writes only the legacy layers, so it cannot save that template.
  const saveErrorMessage = error => {
    const { status, data } = error.response ?? {}

    if (status === 409 && data?.code === 'legacy_edit_rejected_for_v2') {
      return 'This editor cannot save this template, because the template has a v2 editor document. '
        + 'Your changes are not saved. Edit the template with the document API or the MCP editor tools.'
    }

    if (status === 422 && data?.errors) {
      return Object.values(data.errors)[0][0]
    }

    return 'The template is not saved. Try again.'
  }

  // The message is the answer to a failed click, so the promise stops here.
  const save = () => updateTemplate().catch(() => {})

  // The unsaved-changes guard asks us to save before it navigates away.
  const onRequestSave = () => updateTemplate()
    .then(() => window.emitter.emit('editor:saved'))
    .catch(() => window.emitter.emit('editor:save-failed'))

  onMounted(() => window.emitter.on('editor:request-save', onRequestSave))
  onBeforeUnmount(() => window.emitter.off('editor:request-save', onRequestSave))
</script>
