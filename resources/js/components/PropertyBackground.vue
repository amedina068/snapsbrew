<template>
  <div>
    <template v-if="props.disabled">
      <div>
        <template v-if="isGradient">
          <div class="flex justify-between text-[11px] text-gray-500 font-medium">
            <span>Degrees</span>
            <span>{{ gradientDegrees }}&deg;</span>
          </div>
          <div class="mt-2.5 space-y-1">
            <div
              v-for="(step, index) in gradientPreviewSteps"
              :key="`${index}-${step.color}`"
              class="w-full h-5 flex space-x-2"
            >
              <span class="block w-full h-5 rounded-sm" :style="{ background: step.color }"></span>
              <span class="text-xs text-slate-500 w-12 text-right">{{ step.percentage }}%</span>
            </div>
          </div>
        </template>
        <template v-else>
          <div class="flex items-center space-x-2">
            <div class="w-full">
              <span class="block w-full h-5 rounded-sm" :style="{ background: backgroundColor }"></span>
            </div>
          </div>
        </template>
      </div>
    </template>
    <template v-else>
      <div>
        <template v-if="isGradient">
          <div class="flex justify-between items-baseline">
            <label class="block text-xs font-semibold  text-gray-700">Degrees</label>
            <input class="p-0 m-0 text-xs border-none ring-0 bg-gray-100 focus:bg-gray-50  px-1.5 py-1 border border-gray-200  focus:border-gray-100 transition-all rounded-sm text-gray-900 focus:outline-hidden focus:ring-0" :value="backgroundGradientColor.degrees" type="number" @input="updateGradientDegrees($event.target.value)" min="0" max="360">
          </div>
          <div v-show="backgroundType == 'linear'" class="mt-1">
            <input :value="backgroundGradientColor.degrees" @input="updateGradientDegrees($event.target.value)" type="range" min="0" max="360" name="percentage" id="percentage" class="w-full" aria-describedby="percentage">
            <div class="flex justify-between">
              <span class="text-xs text-gray-500 font-medium">0&deg;</span>
              <span class="text-xs text-gray-500 font-medium">360&deg;</span>
            </div>
          </div>  
          <div class="mt-4 space-y-2">
            <div class="flex space-x-2" v-for="(step, index) in backgroundGradientColor.steps" :key="`${index}_color`">
              <div class="mt-1 w-full">
                <ScColorPicker :value="step.color" @change="updateGradient('color', index, $event)"/>
              </div>
              <div class="relative rounded-sm shadow-xs" v-show="backgroundType == 'linear'">
                <input :value="step.percentage" @input="updateGradient('percentage', index, $event.target.value)" type="text" name="percentage" id="percentage" class="focus:ring-rose-500 focus:border-rose-500 inline-block w-16 pr-8 text-xs border-gray-300 rounded-sm py-1.5 pl-2" aria-describedby="percentage" placeholder="default">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 text-xs" id="percentage">
                    %
                  </span>
                </div>
              </div>
              <button v-if="index > 1" class="rounded-xs font-semibold text-gray-700 border border-gray-300 px-2 py-1 inline-flex items-center justify-center" @click.prevent="removeStep(index)">
                <TrashIcon class="h-4 w-4" aria-hidden="true" />

              </button>
            </div>
            <button @click.prevent="addStep" class="rounded-xs text-xs font-semibold text-gray-700 border border-gray-300 px-2 py-1 inline-flex items-center justify-center">
              Add color
            </button>
          </div>
        </template>
        <template v-if="! isGradient">
          <div class="flex justify-start items-center space-x-2">
            <ScColorPicker :value="backgroundColor" @change="updateColor"/>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
  import { TrashIcon } from '@heroicons/vue/24/outline'
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  import ScColorPicker  from './ScColorPicker.vue'

  const props = defineProps({
    disabled: {
      type: Boolean,
      default: false,
    },
  })

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)
  const backgroundType = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundType').value : '')
  const backgroundColor = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundColor').value : '#000000')
  const backgroundGradientColor = computed(() => layer.value ? store.getters.property(layer.value.id, 'backgroundGradientColor').value : { steps: [], degrees: 0 })
  const isGradient = computed(() => backgroundType.value == 'linear' || backgroundType.value == 'radial')
  const gradientPreviewSteps = computed(() => {
    const value = backgroundGradientColor.value || {}
    if (Array.isArray(value.steps) && value.steps.length) {
      return value.steps
    }
    const fallbackColors = [value.from, value.to].filter(Boolean)
    if (fallbackColors.length) {
      return fallbackColors.map(color => ({ color }))
    }
    return []
  })
  const gradientDegrees = computed(() => (backgroundGradientColor.value && backgroundGradientColor.value.degrees) || 0)
  const backgroundTypeLabel = computed(() => ({
    linear: 'Linear',
    radial: 'Radial',
    color: 'Color',
  }[backgroundType.value] || 'Background'))

  const setUndo = payload => store.commit('setUndo', payload)

  const removeStep = stepIndex => {
    if (props.disabled) {
      return
    }
    let payload = JSON.parse(JSON.stringify(backgroundGradientColor.value))
    payload.steps = payload.steps.filter((item, index) => index != stepIndex)
    updateLayerProperty({ property: 'backgroundGradientColor', payload })
  }

  const addStep = () => {
    if (props.disabled) {
      return
    }
    let payload = JSON.parse(JSON.stringify(backgroundGradientColor.value))
    payload.steps.push({
      color: '#2463EB',
      percentage: null  
    })
    updateLayerProperty({ property: 'backgroundGradientColor', payload })
  }

  const updateGradientDegrees = newValue => {
    if (props.disabled) {
      return
    }
    let payload = JSON.parse(JSON.stringify(backgroundGradientColor.value))
    payload.degrees = newValue
    updateLayerProperty({ property: 'backgroundGradientColor', payload })
  }

  const updateGradient = (property, stepIndex, newValue) => {
    if (props.disabled) {
      return
    }
    let payload = JSON.parse(JSON.stringify(backgroundGradientColor.value))
    payload.steps = payload.steps.map((step, index) => {
      if (index == stepIndex) {
        step[property] = newValue
      }
      return step
    })

    updateLayerProperty({ property: 'backgroundGradientColor', payload })
  }

  const updateColor = newValue => {
    if (props.disabled) {
      return
    }
    updateLayerProperty({
      property: 'backgroundColor',
      payload: newValue
    })
  }

  const updateLayerProperty = newValue => {
    if (props.disabled || !layer.value) {
      return
    }

    const properties = {
      'backgroundGradientColor': backgroundGradientColor,
      'backgroundColor': backgroundColor,
    }

    const undoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: newValue.property,
        propertyValue: properties[newValue.property].value
      } }
    ]

    const redoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: newValue.property,
        propertyValue: newValue.payload
      } }
    ]

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: newValue.property,
      propertyValue: newValue.payload
    })

    setUndo({ undoActions, redoActions })
  }
</script>
