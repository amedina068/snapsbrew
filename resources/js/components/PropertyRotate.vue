<template>
  <div>
    <div class="flex items-center justify-between gap-2">
      <sb-editor-label for="rotate">Rotation</sb-editor-label>
      <div v-show="! props.disabled" class="relative">
        <input
          class="w-16 rounded-md border border-slate-200 bg-white py-1 pl-2 pr-5 text-right text-xs text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
          :value="rotate"
          type="number"
          name="rotate_value"
          aria-label="Rotation in degrees"
          @input="updateLayerProperty"
          min="0"
          max="360"
        >
        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-xs text-slate-400">&deg;</span>
      </div>
      <span v-show="props.disabled" class="text-sm font-semibold text-slate-800">{{ rotate }}&deg;</span>
    </div>
    <div v-show="! props.disabled" class="mt-2">
      <input :value="rotate" @input="updateLayerProperty" type="range" min="0" max="360" name="rotate" id="rotate" class="w-full accent-indigo-600">
      <div class="mt-1 flex justify-between text-[11px] font-medium text-slate-400">
        <span>0&deg;</span>
        <span>360&deg;</span>
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
  const rotate = computed(() => store.getters.property(layer.value.id, 'rotate').value)

  const updateLayerProperty = event => {
    if (props.disabled) {
      return
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'rotate', propertyValue: rotate.value } }
    ]

    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'rotate', propertyValue: event.target.value } }
    ]

    setUndo({ undoActions, redoActions })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'rotate', propertyValue: event.target.value })
  }
</script>
