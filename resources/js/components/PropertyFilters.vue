<template>
  <div>
    <sb-editor-label>Filters</sb-editor-label>
    <div class="mt-2 space-y-3">
      <div v-for="filter in filters" :key="filter.name">
        <div class="flex items-center justify-between gap-2">
          <label :for="filter.name" class="text-xs font-medium text-slate-600">{{ filter.label }}</label>
          <div class="flex items-center gap-1">
            <input
              class="w-14 rounded-md border border-slate-200 bg-white py-1 px-2 text-right text-xs text-slate-700 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none"
              type="number"
              :value="valueOf(filter)"
              :min="filter.min"
              :max="filter.max"
              :step="filter.step"
              :aria-label="`${filter.label} value`"
              :disabled="props.disabled"
              @input="update(filter, $event.target.value)"
            >
            <button
              type="button"
              class="rounded-sm px-1 text-[11px] text-slate-400 hover:text-slate-600"
              :aria-label="`Reset ${filter.label}`"
              :disabled="props.disabled"
              @click="update(filter, filter.identity)"
            >
              reset
            </button>
          </div>
        </div>
        <input
          :id="filter.name"
          type="range"
          class="mt-1 w-full accent-indigo-600"
          :value="valueOf(filter)"
          :min="filter.min"
          :max="filter.max"
          :step="filter.step"
          :aria-label="filter.label"
          :disabled="props.disabled"
          @input="update(filter, $event.target.value)"
        >
      </div>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({ disabled: { type: Boolean, default: false } })
  const store = useStore()
  const setUndo = payload => store.commit('setUndo', payload)

  const filters = [
    { name: 'filterBlur', label: 'Blur', identity: 0, min: 0, max: 20, step: 0.5 },
    { name: 'filterBrightness', label: 'Brightness', identity: 1, min: 0, max: 3, step: 0.05 },
    { name: 'filterContrast', label: 'Contrast', identity: 1, min: 0, max: 3, step: 0.05 },
    { name: 'filterSaturate', label: 'Saturate', identity: 1, min: 0, max: 3, step: 0.05 },
    { name: 'filterGrayscale', label: 'Grayscale', identity: 0, min: 0, max: 1, step: 0.05 },
  ]

  const layer = computed(() => store.state.selectedLayer)

  const valueOf = filter => {
    const value = store.getters.property(layer.value.id, filter.name).value
    return value === undefined || value === null || value === '' ? filter.identity : value
  }

  const update = (filter, value) => {
    if (props.disabled) {
      return
    }
    const current = valueOf(filter)
    const next = Number(value)
    setUndo({
      undoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: filter.name, propertyValue: current } }],
      redoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: filter.name, propertyValue: next } }],
    })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: filter.name, propertyValue: next })
  }
</script>
