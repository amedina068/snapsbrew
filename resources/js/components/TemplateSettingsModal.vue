<template>
  <UiModal
    :open="open"
    title="Adjust canvas size"
    description="Choose a preset size or enter custom dimensions."
    @close="close"
  >
    <form
      id="template-settings-form"
      :action="`/templates/${templateUuid}?editor=1`"
      method="POST"
      class="space-y-4"
    >
      <input type="hidden" name="_token" :value="csrfToken">
      <input type="hidden" name="_method" value="PATCH">
      <input type="hidden" name="title" :value="layoutTitle">

      <div>
        <label class="block text-sm font-semibold text-gray-800" for="modal-template-size">Preset</label>
        <select id="modal-template-size" name="size" v-model="selectedSize" class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-900 focus:border-gray-500 focus:ring-0 pr-9">
          <optgroup v-for="(platformSizes, platform) in sizes" :key="platform" :label="platform">
            <option v-for="size in platformSizes" :key="size.value" :value="size.value">{{ size.name }}</option>
          </optgroup>
          <option value="custom">Custom</option>
        </select>
      </div>

      <div :class="['grid grid-cols-2 gap-3', selectedSize === 'custom' ? '' : 'hidden']">
        <div>
          <label class="block text-sm font-semibold text-gray-800" for="modal-template-width">Width</label>
          <input id="modal-template-width" name="width" type="number" min="1" step="1" v-model.number="formWidth" class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-900 focus:border-gray-500 focus:ring-0">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-800" for="modal-template-height">Height</label>
          <input id="modal-template-height" name="height" type="number" min="1" step="1" v-model.number="formHeight" class="focus-ring mt-1 block min-h-10 w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-900 focus:border-gray-500 focus:ring-0">
        </div>
      </div>
    </form>

    <template #footer>
      <UiButton variant="ghost" @click="close">Cancel</UiButton>
      <UiButton type="submit" form="template-settings-form">Update size</UiButton>
    </template>
  </UiModal>
</template>

<script setup>
import UiButton from './ui/UiButton.vue'
import UiModal from './ui/UiModal.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useStore } from 'vuex'

// The preset catalog is owned by App\Support\TemplateSizes and passed in from
// the Blade view. Do not restate the presets here — a second list drifts.
const props = defineProps({
  sizes: {
    type: Object,
    default: () => ({}),
  },
})

const store = useStore()

const open = ref(false)
const selectedSize = ref('custom')
const formWidth = ref(0)
const formHeight = ref(0)

const templateUuid = computed(() => store.state.templateUuid)
const layoutTitle = computed(() => store.state.layoutTitle)
const layoutWidth = computed(() => store.state.layoutWidth)
const layoutHeight = computed(() => store.state.layoutHeight)
const layoutSize = computed(() => store.state.layoutSize)

const csrfToken = computed(() => document.querySelector('meta[name=csrf-token]')?.content ?? '')

const flatSizes = computed(() => Object.values(props.sizes).flat())


const syncSizeSelection = () => {
  // Prefer the stored preset value: 1080x1920 is a Story, a Reel and a TikTok
  // video, so dimensions alone cannot tell them apart.
  const match = flatSizes.value.find(size => size.value === layoutSize.value)
    ?? flatSizes.value.find(
      size => Number(size.width) === Number(layoutWidth.value) && Number(size.height) === Number(layoutHeight.value)
    )

  if (match) {
    selectedSize.value = match.value
    formWidth.value = match.width
    formHeight.value = match.height
  } else {
    selectedSize.value = 'custom'
    formWidth.value = Number(layoutWidth.value) || ''
    formHeight.value = Number(layoutHeight.value) || ''
  }
}

const openModal = () => {
  syncSizeSelection()
  open.value = true
  document.body.classList.add('overflow-hidden')
}

const close = () => {
  open.value = false
  document.body.classList.remove('overflow-hidden')
}

watch(selectedSize, newValue => {
  if (newValue === 'custom') {
    return
  }
  const preset = flatSizes.value.find(size => size.value === newValue)
  if (preset) {
    formWidth.value = preset.width
    formHeight.value = preset.height
  }
})

watch([layoutWidth, layoutHeight, layoutSize], () => {
  if (!open.value) {
    syncSizeSelection()
  }
})

onMounted(() => {
  window.emitter.on('openTemplateSettings', openModal)
  syncSizeSelection()
})

onBeforeUnmount(() => {
  window.emitter.off('openTemplateSettings', openModal)
  document.body.classList.remove('overflow-hidden')
})
</script>
