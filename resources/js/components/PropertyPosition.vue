<template>
  <div>
    <div class="grid grid-cols-2 gap-3">
      <div>
        <sb-editor-label for="x_position">X position</sb-editor-label>
        <div class="mt-1">
          <sb-editor-input
            :type="props.disabled ? 'text' : 'number'"
            id="x_position"
            :value="props.disabled ? `${positionX}px` : positionX"
            :disabled="props.disabled"
            @change="updateLayerProperty('positionX', $event)"
          />
        </div>
      </div>
      <div>
        <sb-editor-label for="y_position">Y position</sb-editor-label>
        <div class="mt-1">
          <sb-editor-input
            :type="props.disabled ? 'text' : 'number'"
            id="y_position"
            :value="props.disabled ? `${positionY}px` : positionY"
            :disabled="props.disabled"
            @change="updateLayerProperty('positionY', $event)"
          />
        </div>
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
  const positionX = computed(() => store.getters.property(layer.value.id, 'positionX').value)
  const positionY = computed(() => store.getters.property(layer.value.id, 'positionY').value)
  
  const updateLayerProperty = (property, newValue) => {
    if (props.disabled) {
      return
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: (property == 'positionX' ? positionX : positionY).value } }
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: Number(newValue) } }
    ]
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: property, propertyValue: Number(newValue) })
    setUndo({ undoActions, redoActions })
  }
</script>
