<template>
  <div>
    <div class="flex items-center justify-between gap-2">
      <sb-editor-label for="opacity">Opacity</sb-editor-label>
      <div v-show="! props.disabled" class="relative">
        <input
          class="w-16 rounded-md border border-slate-200 bg-white py-1 pl-2 pr-5 text-right text-xs text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
          :value="percentage"
          type="number"
          name="opacity_value"
          aria-label="Opacity percentage"
          @input="updateLayerProperty"
          min="0"
          max="100"
        >
        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-slate-400">%</span>
      </div>
      <span v-show="props.disabled" class="text-sm font-semibold text-slate-800">{{ percentage }}%</span>
    </div>
    <div v-show="! props.disabled" class="mt-2">
      <input :value="percentage" @input="updateLayerProperty" type="range" min="0" max="100" name="opacity" id="opacity" class="w-full accent-indigo-600">
      <div class="mt-1 flex justify-between text-[11px] font-medium text-slate-400">
        <span>0%</span>
        <span>100%</span>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({
    disabled: {
      type: Boolean,
      default: false,
    },
  })
  const store = useStore()

  const setUndo = payload => store.commit('setUndo', payload)

  const layer = computed(() => store.state.selectedLayer)
  const opacity = computed(() => {
    const value = store.getters.property(layer.value.id, 'opacity').value
    return value === undefined || value === null ? 1 : Number(value)
  })
  const percentage = computed(() => Math.round(opacity.value * 100))

  const updateLayerProperty = event => {
    if (props.disabled) {
      return
    }

    const clamped = Math.min(100, Math.max(0, Number(event.target.value)))
    const newValue = clamped / 100

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'opacity', propertyValue: opacity.value } }
    ]

    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'opacity', propertyValue: newValue } }
    ]

    setUndo({ undoActions, redoActions })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'opacity', propertyValue: newValue })
  }
</script>
