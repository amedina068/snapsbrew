<template>
  <div>
    <sb-editor-label for="mix_blend_mode">Blend mode</sb-editor-label>
    <div class="mt-1">
      <select
        id="mix_blend_mode"
        class="block w-full rounded-md border border-slate-200 bg-white px-2 py-1 text-sm focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
        :value="blendMode"
        :disabled="props.disabled"
        @change="update($event.target.value)"
      >
        <option v-for="mode in modes" :key="mode" :value="mode">{{ mode }}</option>
      </select>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({ disabled: { type: Boolean, default: false } })
  const store = useStore()
  const setUndo = payload => store.commit('setUndo', payload)

  const modes = [
    'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
    'color-dodge', 'color-burn', 'hard-light', 'soft-light',
    'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity',
  ]

  const layer = computed(() => store.state.selectedLayer)
  const blendMode = computed(() => store.getters.property(layer.value.id, 'mixBlendMode').value || 'normal')

  const update = value => {
    if (props.disabled) {
      return
    }
    setUndo({
      undoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'mixBlendMode', propertyValue: blendMode.value } }],
      redoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'mixBlendMode', propertyValue: value } }],
    })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'mixBlendMode', propertyValue: value })
  }
</script>
