<template>
  <div class="flex items-center justify-between gap-2">
    <div class="inline-flex items-center gap-0.5 rounded-lg border border-gray-200 bg-gray-100 p-0.5">
      <button v-for="item in alignButtons" :key="item.label" type="button" :title="item.label" @click.prevent="updatePosition(item.handler)" class="size-6 rounded-md flex items-center justify-center text-gray-500 hover:bg-white hover:text-gray-900 hover:shadow-xs focus-ring">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path v-for="(d, i) in item.paths" :key="i" :d="d" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" stroke="currentColor"/>
        </svg>
      </button>
    </div>
    <button type="button" title="Fit to template" @click.prevent="fullSize" class="size-7 rounded-lg flex items-center justify-center border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-900 focus-ring">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M8 3H5C3.89543 3 3 3.89543 3 5V8M16 3H19C20.1046 3 21 3.89543 21 5V8M21 16V19C21 20.1046 20.1046 21 19 21H16M8 21H5C3.89543 21 3 20.1046 3 19V16" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" stroke="currentColor"/>
      </svg>
    </button>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const positionLeft = (layer, layoutWidth, layoutHeight) => {
    const positionX = 0
    const positionY = null
    return {
      positionX,
      positionY
    }
  }

  const positionRight = (layer, layoutWidth, layoutHeight) => {
    const positionX = layoutWidth - layer.properties.find(property => property.name == 'width').value
    const positionY = null
    return {
      positionX,
      positionY
    }
  }

  const positionVerticalCenter = (layer, layoutWidth, layoutHeight) => {
    const positionX = null
    const positionY = (layoutHeight / 2) - (layer.properties.find(property => property.name == 'height').value / 2)
    return {
      positionX,
      positionY
    }
  }

  const positionHorizontalCenter = (layer, layoutWidth, layoutHeight) => {
    const positionX = (layoutWidth / 2) - (layer.properties.find(property => property.name == 'width').value / 2)
    const positionY = null
    return {
      positionX,
      positionY
    }
  }

  const positionBottom = (layer, layoutWidth, layoutHeight) => {
    const positionX = null
    const positionY = layoutHeight - layer.properties.find(property => property.name == 'height').value
    return {
      positionX,
      positionY
    }
  }

  const positionTop = (layer, layoutWidth, layoutHeight) => {
    const positionX = null
    const positionY = 0
    return {
      positionX,
      positionY
    }
  }
    const alignButtons = [
      { label: 'Align top', handler: positionTop, paths: ['M20 0.500008L2.02605e-07 0.500004M9.99999 20L9.99999 3.50001M9.99999 3.50001L18 8.50001M9.99999 3.50001L1.99999 8.50001'] },
      { label: 'Align vertical center', handler: positionVerticalCenter, paths: ['M22 12L12 12L2 12M12 22L12 14.5M12 14.5L20 19.5M12 14.5L3.99998 19.5', 'M12 2L12 9.5M12 9.5L4 4.5M12 9.5L20 4.5'] },
      { label: 'Align bottom', handler: positionBottom, paths: ['M2 21.5L22 21.5M12 2V18.5M12 18.5L4 13.5M12 18.5L20 13.5'] },
      { label: 'Align left', handler: positionLeft, paths: ['M0.500015 -8.52371e-07L0.500011 10L0.500011 20M20 10L3.50001 10M3.50001 10L8.50001 2.00001M3.50001 10L8.50001 18'] },
      { label: 'Align horizontal center', handler: positionHorizontalCenter, paths: ['M12 22L12 12L12 2M2 12L9.5 12M9.5 12L4.5 20M9.5 12L4.5 3.99998', 'M22 12L14.5 12M14.5 12L19.5 4M14.5 12L19.5 20'] },
      { label: 'Align right', handler: positionRight, paths: ['M21.5 22L21.5 12L21.5 2M2 12L18.5 12M18.5 12L13.5 20M18.5 12L13.5 3.99998'] },
    ]

    const store = useStore()
    const layer = computed(() => store.state.selectedLayer)
    const width = computed(() => Number(store.state.layoutWidth))
    const height = computed(() => Number(store.state.layoutHeight))
    const left = computed(() => store.getters.property(layer.value.id, 'positionX').value)
    const top = computed(() => store.getters.property(layer.value.id, 'positionY').value)
    const updatePosition = position => {
      const { positionX, positionY } = position(layer.value, width.value, height.value)
      const undoActions = []
      const redoActions = []

      if (positionX !== null) {
        undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: left.value } })
        redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: Number(positionX) } })
      }

      if (positionY !== null) {
        undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: top.value } })
        redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: Number(positionY) } })
      }
      
      if (positionX !== null) {
        store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionX', propertyValue: Number(positionX) })
      }

      if (positionY !== null) {
        store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionY', propertyValue: Number(positionY) })
      }
      if (undoActions.length) {
        setUndo({ undoActions, redoActions })
      }
    }

    const layerWidth = computed(() => store.getters.property(layer.value.id, 'width').value)
    const layerHeight = computed(() => store.getters.property(layer.value.id, 'height').value)
    const fullSize = () => {
      const targets = [
        { propertyName: 'positionX', oldValue: left.value, newValue: 0 },
        { propertyName: 'positionY', oldValue: top.value, newValue: 0 },
        { propertyName: 'width', oldValue: layerWidth.value, newValue: width.value },
        { propertyName: 'height', oldValue: layerHeight.value, newValue: height.value },
      ]
      const undoActions = []
      const redoActions = []
      targets.forEach(({ propertyName, oldValue, newValue }) => {
        undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName, propertyValue: oldValue } })
        redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName, propertyValue: Number(newValue) } })
        store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName, propertyValue: Number(newValue) })
      })
      setUndo({ undoActions, redoActions })
    }

    const setUndo = payload => store.commit('setUndo', payload)
</script>