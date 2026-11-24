<template>
  <div>
    <sb-editor-label for="svg_background">Background</sb-editor-label>
    <div class="flex items-start space-x-2">
      <div class="min-w-0 flex-1">
        <SvgDesignSelect :value="backgroundSvg.design" @change="updateSvg('design', $event)" />
      </div>
      <div class="svg-bg-color mt-1 w-9 shrink-0">
        <ScColorPicker :value="backgroundSvg.color" @change="updateSvg('color', $event)"/>
      </div>
    </div>
  </div>
</template>

<style>
  /* Match the colour swatch to the height of the design select beside it. */
  .svg-bg-color .pcr-button {
    height: 2.125rem;
    border-radius: 0.375rem;
    border: 1px solid rgb(203 213 225); /* slate-300 */
  }
</style>

<script setup>
  import { computed, watch } from 'vue'
  import { useStore } from 'vuex'
  import ScColorPicker  from './ScColorPicker.vue'
  import SvgDesignSelect from './SvgDesignSelect.vue'

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)
  const backgroundSvg = computed(() => store.getters.property(layer.value.id, 'backgroundSvg').value)

  const setUndo = payload => store.commit('setUndo', payload)

  const updateSvg = (property, newValue) => {
    const undoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: 'backgroundSvg',
        propertyValue: backgroundSvg.value
      } }
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: 'backgroundSvg',
        propertyValue: { ...backgroundSvg.value, [property]: newValue }
      } }
    ]

    setUndo({ undoActions, redoActions })

    updateLayerProperty({
      design: property == 'design' ? newValue : backgroundSvg.value.design,
      color: property == 'color' ? newValue : backgroundSvg.value.color,
    })
  }

  const updateLayerProperty = newValue => {
    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: 'backgroundSvg',
      propertyValue: newValue
    })
  }

</script>