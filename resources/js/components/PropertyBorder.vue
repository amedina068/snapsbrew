<template>
  <div id="lorem">
    <div class="flex items-center justify-between">
      <sb-editor-label for="border_style_solid">Border</sb-editor-label>
      <button
        v-if="hasBorder"
        type="button"
        :disabled="props.disabled"
        class="text-xs font-medium transition-colors"
        :class="[props.disabled ? 'text-slate-400 cursor-not-allowed' : 'text-rose-600 hover:text-rose-700']"
        @click.prevent="removeBorder"
      >
        Remove border
      </button>
    </div>

    <div v-if="hasBorder" class="mt-2 space-y-3">
      <div class="grid grid-cols-4 gap-1.5">
        <button
          v-for="option in borderStyleOptions"
          :key="option.value"
          type="button"
          :id="`border_style_${option.value}`"
          :disabled="props.disabled"
          :class="styleButtonClasses(option.value)"
          @click.prevent="setBorderStyle(option.value)"
        >
          {{ option.label }}
        </button>
      </div>

      <div>
        <sb-editor-label for="border_width">Width</sb-editor-label>
        <div class="mt-1">
          <sb-editor-input
            id="border_width"
            type="number"
            :value="borderWidth"
            :disabled="props.disabled"
            @change="updateLayerProperty('borderWidth', $event)"
          />
        </div>
      </div>

      <div>
        <sb-editor-label for="border_color">Color</sb-editor-label>
        <div class="mt-2">
          <ScColorPicker
            v-if="!props.disabled"
            :value="borderColor"
            @change="updateLayerProperty('borderColor', $event)"
          />
          <div v-else class="border-2 border-gray-200 h-5 w-5 rounded-sm flex items-center justify-center">
            <span class="w-4 h-4 rounded-sm" :style="{ background: borderColor }"></span>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="mt-1">
      <button
        v-show="! props.disabled"
        type="button"
        :disabled="props.disabled"
        class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition-colors"
        :class="props.disabled ? 'cursor-not-allowed opacity-60' : 'hover:bg-slate-50 hover:text-slate-900'"
        @click.prevent="addBorder"
      >
        Add border
      </button>
      <span v-show="props.disabled" class="text-sm font-semibold text-slate-800">No border</span>
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

  const layer = computed(() => store.state.selectedLayer)

  const getPropertyValue = (propertyName, fallback) => {
    if (!layer.value) {
      return fallback
    }

    const property = store.getters.property(layer.value.id, propertyName)
    return property && property.value !== undefined ? property.value : fallback
  }

  const borderStyle = computed(() => getPropertyValue('borderStyle', 'none'))
  const borderWidth = computed(() => getPropertyValue('borderWidth', 1))
  const borderColor = computed(() => getPropertyValue('borderColor', '#000000'))

  const borderStyleOptions = [
    { value: 'solid', label: 'Solid' },
    { value: 'double', label: 'Double' },
    { value: 'dashed', label: 'Dashed' },
    { value: 'dotted', label: 'Dotted' },
  ]

  const hasBorder = computed(() => borderStyle.value !== 'none')

  const setUndo = payload => store.commit('setUndo', payload)

  const propertyValue = property => ({
    borderStyle,
    borderWidth,
    borderColor,
  })[property].value

  const updateLayerProperty = (property, newValue) => {
    if (props.disabled || !layer.value) {
      return
    }

    let value = newValue

    if (property === 'borderWidth') {
      value = Number(newValue)

      if (!Number.isFinite(value)) {
        value = 0
      }
    }

    if (propertyValue(property) === value) {
      return
    }

    const undoActions = [
      {
        action: 'updateLayerProperty',
        payload: {
          layerId: layer.value.id,
          propertyName: property,
          propertyValue: propertyValue(property),
        },
      },
    ]

    const redoActions = [
      {
        action: 'updateLayerProperty',
        payload: {
          layerId: layer.value.id,
          propertyName: property,
          propertyValue: value,
        },
      },
    ]

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: property,
      propertyValue: value,
    })

    setUndo({ undoActions, redoActions })
  }

  const addBorder = () => updateLayerProperty('borderStyle', 'solid')
  const removeBorder = () => updateLayerProperty('borderStyle', 'none')
  const setBorderStyle = style => updateLayerProperty('borderStyle', style)

  const styleButtonClasses = style => {
    const isActive = borderStyle.value === style

    const baseClasses = 'px-2 py-1.5 text-xs font-medium rounded-md border transition-colors'
    const interactiveClasses = props.disabled ? 'cursor-not-allowed opacity-60' : (isActive ? '' : 'hover:border-slate-300 hover:bg-slate-50')
    const stateClasses = isActive ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-600'

    return [baseClasses, interactiveClasses, stateClasses].join(' ')
  }
</script>
