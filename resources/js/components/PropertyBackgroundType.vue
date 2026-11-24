<template>
  <div>
    <div>
      <sb-editor-label for="background_type">Background</sb-editor-label>
      <template v-if="props.disabled">
        <div class="mt-1 px-2 py-1 bg-gray-100 rounded-sm text-xs text-gray-600 font-medium uppercase tracking-wide">
          {{ readableBackgroundType }}
        </div>
      </template>
      <template v-else>
        <sb-editor-select
          @change="updateLayerProperty"
          id="background_type"
          :options="options"
          :value="backgroundType"
          optionText="readableName"
        ></sb-editor-select>
      </template>
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

  let options = [
    {value: 'linear', name: 'linearGradient', readableName: 'Linear gradient'},
    {value: 'color', name: 'color', readableName: 'Color'},
    {value: 'radial', name: 'radialGradient', readableName: 'Radial gradient'},
  ]

  const store = useStore()

  const setUndo = payload => store.commit('setUndo', payload)

  const layer = computed(() => store.state.selectedLayer)
  const backgroundType = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundType').value : '')
  const backgroundGradientColor = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundGradientColor').value : { steps: [], degrees: 0 })
  const backgroundColor = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundColor').value : '#000000')
  const readableBackgroundType = computed(() => {
    const option = options.find(option => option.value === backgroundType.value)
    return option ? option.readableName : 'Background'
  })

  const updateLayerProperty = event => {
    if (props.disabled || !layer.value) {
      return
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: 'backgroundType',
        propertyValue: backgroundType.value
      } }
    ]

    const redoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: 'backgroundType',
        propertyValue: event
      } },
    ]

    if (event == 'color') {
      undoActions.push({ action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'backgroundColor' ]
        } }, { action: 'addLayerProperties', payload: {
        layerId: layer.value.id,
        properties: [
          {
            name: 'backgroundGradientColor',
            value: {
              from: backgroundGradientColor.value.steps[0].color,
              to: backgroundGradientColor.value.steps[0].color,
              degrees: backgroundGradientColor.value.degrees,
            }
          }
        ]
      } })
      redoActions.push({ action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'backgroundGradientColor' ]
        } }, { action: 'addLayerProperties', payload: {
        layerId: layer.value.id,
        properties: [
          {
            name: 'backgroundColor',
            value: backgroundGradientColor.value.from
          }
        ]
      } })
    } else if(backgroundType.value != 'linear' && backgroundType.value != 'radial') {
      undoActions.push({ action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'backgroundGradientColor' ]
        } },  { action: 'addLayerProperties', payload: {
        layerId: layer.value.id,
        properties: [
          {
            name: 'backgroundColor',
            value: backgroundColor.value
          }
        ]
      } })
      redoActions.push({ action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'backgroundColor' ]
        } }, { action: 'addLayerProperties', payload: {
        layerId: layer.value.id,
        properties: [
          {
            name: 'backgroundGradientColor',
            value: {
              from: backgroundColor.value,
              to: backgroundColor.value,
              degrees: 0,
            }
          }
        ]
      } })
    }

    if (event == 'color') {
      store.commit('addLayerProperties', {
        layerId: layer.value.id,
        properties: [
          { name: 'backgroundColor', value: backgroundGradientColor.value.steps[0].color }
        ]
      })
      store.commit('removeLayerProperties', {
        layerId: layer.value.id,
        properties: [ 'backgroundGradientColor' ]
      })
    } else if(backgroundType.value != 'linear' && backgroundType.value != 'radial') {
      store.commit('addLayerProperties', {
        layerId: layer.value.id,
        properties: [
          { name: 'backgroundGradientColor', value: {
            steps: [
              {color: backgroundColor.value, percentage: 0 },
              {color: backgroundColor.value, percentage: 100 },
            ],
            degrees: 0 }
          }
        ]
      })
      store.commit('removeLayerProperties', {
        layerId: layer.value.id,
        properties: [ 'backgroundColor' ]
      })
    }

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: 'backgroundType',
      propertyValue: event
    })

    setUndo({ undoActions, redoActions })
  }
</script>
