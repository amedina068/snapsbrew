<template>
  <div>
    <div>
      <sb-editor-label for="background_image">Image</sb-editor-label>
      <div class="mt-1">
        <BackgroundImageSelector
          :value="backgroundImage"
          @updatedImage="updateImageProperty"
        />
      </div>
    </div>
    <fieldset class="mt-2">
      <legend class="text-sm font-medium text-gray-700">Focal point</legend>
      <p class="text-xs text-gray-500">Manual focal point overrides the crop default.</p>
      <div class="mt-1 grid grid-cols-2 gap-2">
        <label class="text-sm text-gray-700">X <input class="w-full" type="number" min="0" max="100" :value="focalX" @change="updateFocal('imageFocalX', $event.target.value)" /></label>
        <label class="text-sm text-gray-700">Y <input class="w-full" type="number" min="0" max="100" :value="focalY" @change="updateFocal('imageFocalY', $event.target.value)" /></label>
      </div>
    </fieldset>
    <div class="mt-2">
      <sb-editor-label for="background_size">Image Size</sb-editor-label>
      <div class="mt-1">
        <sb-editor-select
          id="background_size"
          :value="backgroundSize"
          @change="updateBackgroundSizeProperty"
          :options="backgroundSizeOptions"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick } from 'vue'
import { useStore } from 'vuex'
import BackgroundImageSelector from './BackgroundImageSelector.vue'
// Assuming sb-editor-label and sb-editor-select are globally registered or imported elsewhere

const store = useStore()
const layer = computed(() => store.state.selectedLayer)
const layoutWidth = computed(() => store.state.layoutWidth)

// Computed properties for v-model binding
const backgroundImage = computed(() => store.getters.property(layer.value.id, 'backgroundImage').value)
const backgroundSize = computed(() => store.getters.property(layer.value.id, 'backgroundSize').value || 'cover') // Default to cover
const focalX = computed(() => store.getters.property(layer.value.id, 'imageFocalX').value ?? 50)
const focalY = computed(() => store.getters.property(layer.value.id, 'imageFocalY').value ?? 50)

// Original properties needed for undo/redo state
const originalWidth = computed(() => store.getters.property(layer.value.id, 'width').value)
const originalHeight = computed(() => store.getters.property(layer.value.id, 'height').value)
const originalAspectRatio = computed(() => store.getters.property(layer.value.id, 'aspectRatio').value)
const originalBackgroundSize = computed(() => store.getters.property(layer.value.id, 'backgroundSize').value)

const setUndo = payload => store.commit('setUndo', payload)

const backgroundSizeOptions = [
  { value: 'cover', name: 'Cover' },
  { value: 'contain', name: 'Contain' },
  { value: 'auto', name: 'Auto' },
  { value: '100% 100%', name: 'Stretch' },
]

// Renamed original updateLayerProperty to specifically handle image updates
const updateImageProperty = newValue => {
  const previousImage = backgroundImage.value

  if (!newValue) {
    if (!previousImage) {
      return
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: previousImage } },
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: null } },
    ]

    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: null })
    setUndo({ undoActions, redoActions })
    return
  }

  nextTick(() => {
    const image = new Image
    image.src = newValue
    const imageAspectRatio = image.width / image.height
    // Keep original width, calculate height based on aspect ratio
    const newWidth = originalWidth.value // Or adjust logic as needed
    const newHeight = newWidth / imageAspectRatio

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: originalHeight.value } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: originalWidth.value } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: originalAspectRatio.value } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: previousImage } },
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: imageAspectRatio } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: newValue } },
    ]

    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: imageAspectRatio })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'backgroundImage', propertyValue: newValue })

    setUndo({ undoActions, redoActions })
  })
}

// Method to update only the background size property
const updateBackgroundSizeProperty = newValue => {
  const undoActions = [
    { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundSize', propertyValue: originalBackgroundSize.value || 'cover' } } // Use default if original is null
  ]
  const redoActions = [
    { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'backgroundSize', propertyValue: newValue } }
  ]
  store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'backgroundSize', propertyValue: newValue })
  setUndo({ undoActions, redoActions })
}

const updateFocal = (propertyName, value) => {
  const previous = store.getters.property(layer.value.id, propertyName).value ?? 50
  const next = Math.max(0, Math.min(100, Number(value)))
  store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName, propertyValue: next })
  setUndo({ undoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName, propertyValue: previous } }], redoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName, propertyValue: next } }] })
}
</script>
