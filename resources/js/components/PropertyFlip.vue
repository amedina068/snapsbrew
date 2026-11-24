<template>
  <div>
    <sb-editor-label>Flip</sb-editor-label>
    <div class="mt-1 flex gap-2">
      <button
        type="button"
        class="flex-1 rounded-md border px-2 py-1 text-xs font-semibold transition-colors"
        :class="flipHorizontal ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
        :aria-pressed="flipHorizontal.toString()"
        aria-label="Flip horizontal"
        :disabled="props.disabled"
        @click="toggle('flipHorizontal', flipHorizontal)"
      >
        Horizontal
      </button>
      <button
        type="button"
        class="flex-1 rounded-md border px-2 py-1 text-xs font-semibold transition-colors"
        :class="flipVertical ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'"
        :aria-pressed="flipVertical.toString()"
        aria-label="Flip vertical"
        :disabled="props.disabled"
        @click="toggle('flipVertical', flipVertical)"
      >
        Vertical
      </button>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({ disabled: { type: Boolean, default: false } })
  const store = useStore()
  const setUndo = payload => store.commit('setUndo', payload)

  const layer = computed(() => store.state.selectedLayer)
  const flipHorizontal = computed(() => store.getters.property(layer.value.id, 'flipHorizontal').value === true)
  const flipVertical = computed(() => store.getters.property(layer.value.id, 'flipVertical').value === true)

  const toggle = (name, current) => {
    if (props.disabled) {
      return
    }
    const next = ! current
    setUndo({
      undoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: name, propertyValue: current } }],
      redoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: name, propertyValue: next } }],
    })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: name, propertyValue: next })
  }
</script>
