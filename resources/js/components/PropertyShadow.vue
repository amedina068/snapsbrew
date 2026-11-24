<template>
  <div>
    <div class="flex justify-between">
      <sb-editor-label for="shadow">Shadow</sb-editor-label>
    </div>
    
    <div class="mt-2 space-y-3" v-if="layerHasShadow">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <sb-editor-label for="shadow_x">Horizontal</sb-editor-label>
          <div class="mt-1">
            <sb-editor-input
              type="number"
              :value="shadowX"
              @change="updateLayerProperty('x', $event)"
              id="shadow_x"
            />
          </div>
        </div>
        <div>
          <sb-editor-label for="shadow_y">Vertical</sb-editor-label>
          <div class="mt-1">
            <sb-editor-input
              type="number"
              :value="shadowY"
              @change="updateLayerProperty('y', $event)"
              id="shadow_y"
            />
          </div>
        </div>
        <div>
          <sb-editor-label for="shadow_blur">Blur</sb-editor-label>
          <div class="mt-1">
            <sb-editor-input
              type="number"
              :value="shadowBlur"
              @change="updateLayerProperty('blur-sm', $event)"
              id="shadow_blur"
            />
          </div>
        </div>
        <div>
          <sb-editor-label for="shadow_spread">Spread</sb-editor-label>
          <div class="mt-1">
            <sb-editor-input
              type="number"
              :value="shadowSpread"
              @change="updateLayerProperty('spread', $event)"
              id="shadow_spread"
            />
          </div>
        </div>
      </div>
      <div>
        <sb-editor-label for="shadow_color">Color</sb-editor-label>
        <div class="mt-1.5">
          <ScColorPicker :value="shadowColor" @change="updateLayerProperty('color', $event)"/>
        </div>
      </div>
      <button type="button" class="text-xs font-medium text-rose-600 transition-colors hover:text-rose-700" @click.prevent="removeShadow">Remove shadow</button>
    </div>
    <div v-else class="mt-1.5">
      <button v-show="!props.disabled" type="button" class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50 hover:text-slate-900" @click.prevent="addShadow">Add shadow</button>
      <span v-show="props.disabled" class="text-sm font-semibold text-slate-800">No shadow</span>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  import ScColorPicker from './ScColorPicker'

  const props = defineProps({
    disabled: {
      type: Boolean,
      default: false,
    },
  })
  
  const store = useStore()
  const setUndo = payload => store.commit('setUndo', payload)
  const layer = computed(() => store.state.selectedLayer)
  const layerHasShadow = computed(() => Object.keys(store.getters.property(layer.value.id, 'shadow-sm')).length)
  const shadow = computed(() => store.getters.property(layer.value.id, 'shadow-sm').value)
  const shadowX = computed(() => shadow.value ? shadow.value.x : null)
  const shadowY = computed(() => shadow.value ? shadow.value.y : null)
  const shadowBlur = computed(() => shadow.value ? shadow.value.blur : null)
  const shadowSpread = computed(() => shadow.value ? shadow.value.spread : null)
  const shadowColor = computed(() => shadow.value ? shadow.value.color : null)
  const addShadow = () => {
    const undoActions = [
      {
        action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'shadow-sm' ]
        }
      }
    ]

    const redoActions = [
      {
        action: 'addLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [
            {
              name: 'shadow-sm',
              value: {
                color: 'rgba(0,0,0,.25)',
                blur: 6,
                spread: 1,
                x: 0,
                y: 4,
              }
            }
          ]
        }
      }
    ]

    setUndo({ undoActions, redoActions })
    
    store.commit('addLayerProperties', {
      layerId: layer.value.id,
      properties: [
        {name: 'shadow-sm', value: {
          color: 'rgba(0,0,0,.25)',
          blur: 6,
          spread: 1,
          x: 0,
          y: 4,
        }}
      ]
    })
  }

  const updateLayerProperty = (property, value) => {
    const undoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'shadow-sm', propertyValue: shadow.value } }
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'shadow-sm', propertyValue: {...shadow.value, [property]:value } } }
    ]
    setUndo({ undoActions, redoActions })
    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: 'shadow-sm',
      propertyValue: {...shadow.value, [property]:value }
    })

  }

  const removeShadow = () => {
    const undoActions = [
      {
        action: 'addLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [
            {
              name: 'shadow-sm',
              value: {
                color: shadowColor.value,
                blur: shadowBlur.value,
                spread: shadowSpread.value,
                x: shadowX.value,
                y: shadowY.value,
              }
            }
          ]
        }
      }
    ]
    const redoActions = [
      {
        action: 'removeLayerProperties',
        payload: {
          layerId: layer.value.id,
          properties: [ 'shadow-sm' ]
        }
      }
    ]
    setUndo({ undoActions, redoActions })
    store.commit('removeLayerProperties', {
      layerId: layer.value.id,
      properties: [ 'shadow-sm' ]
    })
  }
</script>