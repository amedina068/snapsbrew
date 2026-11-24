<template>
  <div>
    <div>
      <sb-editor-label for="border_radius_all">Border radius</sb-editor-label>
      <div v-if="showGeneralInput" class="mt-2">
        <sb-editor-input
          type="number"
          id="border_radius_all"
          :value="borderRadiusLeftTop"
          :disabled="props.disabled"
          @change="updateLayerProperty('borderRadiusLeftTop', $event)"
        />
      </div>
    </div>

    <div v-if="!showGeneralInput" class="mt-4 space-y-4">
      <div>
        <sb-editor-label for="border_radius_left_top" class="mb-1">Top left corner</sb-editor-label>
        <sb-editor-input
          type="number"
          id="border_radius_left_top"
          @change="updateLayerProperty('borderRadiusLeftTop', $event)"
          :value="borderRadiusLeftTop"
          :disabled="props.disabled"
        />
      </div>

      <div>
        <sb-editor-label for="border_radius_right_top">Top right corner</sb-editor-label>
        <sb-editor-input
          type="number"
          id="border_radius_right_top"
          @change="updateLayerProperty('borderRadiusRightTop', $event)"
          :value="borderRadiusRightTop"
          :disabled="props.disabled"
        />
      </div>

      <div>
        <sb-editor-label for="border_radius_right_bottom">Bottom right corner</sb-editor-label>
        <sb-editor-input
          type="number"
          id="border_radius_right_bottom"
          @change="updateLayerProperty('borderRadiusRightBottom', $event)"
          :value="borderRadiusRightBottom"
          :disabled="props.disabled"
        />
      </div>

      <div>
        <sb-editor-label for="border_radius_left_bottom">Bottom left corner</sb-editor-label>
        <sb-editor-input
          type="number"
          id="border_radius_left_bottom"
          @change="updateLayerProperty('borderRadiusLeftBottom', $event)"
          :value="borderRadiusLeftBottom"
          :disabled="props.disabled"
        />
      </div>
    </div>

    <div v-if="!props.disabled" class="mt-1">
      <button class="flex items-center text-xs font-medium text-slate-500 transition-colors hover:text-slate-700" @click.prevent="updateLayerProperty('borderRadiusLocked', ! borderRadiusLocked)">
        <LockClosedIcon class="h-4 w-4" v-show="borderRadiusLocked" aria-hidden="true" />
        <LockOpenIcon class="h-4 w-4" v-show="! borderRadiusLocked" aria-hidden="true" />
        <span class="ml-2">{{ borderRadiusLocked ? 'Unlock' : 'Lock' }} corners</span>
      </button>
    </div>
  </div>
</template>

<script setup>
  import { LockClosedIcon, LockOpenIcon } from '@heroicons/vue/outline'
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

  const borderRadiusLocked = computed(() => layer.value ? store.getters.property(layer.value.id, 'borderRadiusLocked').value : null)
  const borderRadiusLeftTop = computed(() => layer.value ? store.getters.property(layer.value.id, 'borderRadiusLeftTop').value : null)
  const borderRadiusRightTop = computed(() => layer.value ? store.getters.property(layer.value.id, 'borderRadiusRightTop').value : null)
  const borderRadiusRightBottom = computed(() => layer.value ? store.getters.property(layer.value.id, 'borderRadiusRightBottom').value : null)
  const borderRadiusLeftBottom = computed(() => layer.value ? store.getters.property(layer.value.id, 'borderRadiusLeftBottom').value : null)

  const isUniform = computed(() => {
    const values = [
      borderRadiusLeftTop.value,
      borderRadiusRightTop.value,
      borderRadiusRightBottom.value,
      borderRadiusLeftBottom.value,
    ].map(value => Number(value))

    return values.every(value => value === values[0])
  })

  const showGeneralInput = computed(() => props.disabled ? isUniform.value : borderRadiusLocked.value)

  const updateLayerProperty = (property, newValue) => {
    if (props.disabled) {
      return
    }

    const value = Number(newValue)
    const undoActions = []
    const redoActions = []

    const updatedPropertyValue = property => ({
      'borderRadiusLocked': borderRadiusLocked,
      'borderRadiusLeftTop': borderRadiusLeftTop,
      'borderRadiusRightTop': borderRadiusRightTop,
      'borderRadiusRightBottom': borderRadiusRightBottom,
      'borderRadiusLeftBottom': borderRadiusLeftBottom,
    })[property].value

    if (borderRadiusLocked.value && property != 'borderRadiusLocked') {
      undoActions.push(...[
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusLeftTop', propertyValue: borderRadiusLeftTop.value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusRightTop', propertyValue: borderRadiusRightTop.value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusRightBottom', propertyValue: borderRadiusRightBottom.value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusLeftBottom', propertyValue: borderRadiusLeftBottom.value } }
      ])

      redoActions.push(...[
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusLeftTop', propertyValue: value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusRightTop', propertyValue: value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusRightBottom', propertyValue: value } },
        { action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'borderRadiusLeftBottom', propertyValue: value  }}
      ])

      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'borderRadiusLeftTop', propertyValue: value })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'borderRadiusRightTop', propertyValue: value })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'borderRadiusRightBottom', propertyValue: value })
      store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'borderRadiusLeftBottom', propertyValue: value })
      return setUndo({ undoActions, redoActions })
    }

    undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: updatedPropertyValue(property) } })

    redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: property, propertyValue: value } })

    setUndo({ undoActions, redoActions })

    return store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: property,
      propertyValue: value
    })
  }

</script>
