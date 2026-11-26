<template>
  <div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <sb-editor-label for="width">Width</sb-editor-label>
        <div class="mt-1">
          <sb-editor-input
            :type="props.disabled ? 'text' : 'number'"
            id="size_width"
            :value="props.disabled ? `${width}px` : width"
            :disabled="props.disabled"
            @change="updateLayerProperty('width', Number($event))"
          />
        </div>
      </div>
      <div>
        <sb-editor-label for="height">Height</sb-editor-label>
        <div class="mt-1">
          <sb-editor-input
            :type="props.disabled ? 'text' : 'number'"
            id="size_height"
            :value="props.disabled ? `${height}px` : height"
            :disabled="props.disabled"
            @change="updateLayerProperty('height', Number($event))"
          />
        </div>
      </div>
    </div>
    <div v-if="!props.disabled && !isQrLayer" class="mt-2">
      <sb-editor-label for="aspect_ratio">Aspect ratio</sb-editor-label>
      <div class="mt-1">
        <aspect-ratio-select
          :width="width"
          :height="height"
          @select="applyAspectRatio"
        />
      </div>
    </div>
    <div v-if="!props.disabled && !isQrLayer" class="mt-1">
      <button
        class="flex items-center text-xs font-medium text-slate-500 transition-colors hover:text-slate-700"
        @click.prevent="updateLayerProperty('aspectRatio', aspectRatio ? null : width / height)"
      >
        <LockClosedIcon class="h-4 w-4" v-show="aspectRatio" aria-hidden="true" />
        <LockOpenIcon class="h-4 w-4" v-show="! aspectRatio" aria-hidden="true" />
        <span class="ml-1">{{ aspectRatio ? 'Unlock' : 'Lock' }} aspect ratio</span>
      </button>
    </div>
  </div>
</template>

<script setup>
  import { LockClosedIcon, LockOpenIcon } from '@heroicons/vue/24/outline'
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  import AspectRatioSelect from './AspectRatioSelect.vue'

  const props = defineProps({
    disabled: {
      type: Boolean,
      default: false,
    },
  })

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)
  const isQrLayer = computed(() => layer.value?.type === 'qr_code')

  const getPropertyValue = (propertyName, fallback = null) => {
    if (!layer.value) {
      return fallback
    }
    const property = store.getters.property(layer.value.id, propertyName)
    return typeof property.value !== 'undefined' ? property.value : fallback
  }

  const width = computed(() => Number(getPropertyValue('width', 0)))
  const height = computed(() => Number(getPropertyValue('height', 0)))
  const storedAspectRatio = computed(() => getPropertyValue('aspectRatio', null))
  const aspectRatio = computed(() => (isQrLayer.value ? 1 : storedAspectRatio.value))

  const updateLayerProperty = (property, newValue) => {
    if (props.disabled) {
      return
    }

    const undoActions = []
    const redoActions = []
    if (property ==  'aspectRatio') {
      undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: aspectRatio.value }})
      redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: newValue } })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: property, propertyValue: newValue })
      return setUndo({ undoActions, redoActions })
    }
    if (! aspectRatio.value) {
      undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: (property == 'width' ? width : height).value }})
      redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: newValue } })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: property, propertyValue: newValue })
      return setUndo({ undoActions, redoActions })
    }

    let newWidth = property == 'width' ? newValue : newValue * aspectRatio.value
    let newHeight = property == 'height' ? newValue : newValue / aspectRatio.value

    undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: width.value } })
    redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth } })
    undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: height.value } })
    redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight } })

    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth })
    return setUndo({ undoActions, redoActions })
  }

  // Apply a preset ratio (width/height) by reshaping the current box.
  // Keeps the longest current side fixed so scale stays stable, then
  // recomputes the other side. Only width/height are stored — never the ratio.
  const applyAspectRatio = ratio => {
    if (props.disabled || !ratio) {
      return
    }

    const longSide = Math.max(width.value, height.value) || 100
    const newWidth = ratio >= 1 ? longSide : Math.round(longSide * ratio)
    const newHeight = ratio >= 1 ? Math.round(longSide / ratio) : longSide

    if (newWidth === width.value && newHeight === height.value) {
      return
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: width.value } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: height.value } },
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth } },
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight } },
    ]

    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'width', propertyValue: newWidth })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'height', propertyValue: newHeight })

    // Keep the lock consistent if the user had aspect ratio locked.
    if (storedAspectRatio.value) {
      undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: storedAspectRatio.value } })
      redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: ratio } })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'aspectRatio', propertyValue: ratio })
    }

    setUndo({ undoActions, redoActions })
  }

  const setUndo = properties => store.commit('setUndo', properties)

</script>
