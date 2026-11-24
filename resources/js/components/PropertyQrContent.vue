<template>
  <div>
    <div>
      <sb-editor-label for="qr_content_type">Content type</sb-editor-label>
      <div class="mt-1">
        <template v-if="props.disabled">
          <div class="px-2 py-1 bg-gray-100 rounded-sm text-xs font-semibold uppercase tracking-wide text-gray-600">
            {{ readableContentType }}
          </div>
        </template>
        <template v-else>
          <sb-editor-select
            id="qr_content_type"
            :value="qrContentType"
            :options="contentTypeOptions"
            @change="value => updateLayerProperty('qrContentType', value, qrContentType)"
          />
        </template>
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="qr_content_value">Content</sb-editor-label>
      <div class="mt-1">
        <template v-if="props.disabled">
          <div class="px-2 py-1 bg-gray-50 rounded-sm border border-gray-200 text-xs text-gray-600 min-h-[40px] wrap-break-word">
            {{ qrContent || 'No default content' }}
          </div>
        </template>
        <template v-else>
          <sb-editor-textarea
            id="qr_content_value"
            :value="qrContent"
            @change="value => updateLayerProperty('qrContent', value, qrContent)"
          />
        </template>
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
    }
  })

  const store = useStore()

  const contentTypeOptions = [
    { value: 'url', name: 'URL' },
    { value: 'text', name: 'Text' },
  ]

  const setUndo = payload => store.commit('setUndo', payload)
  const layer = computed(() => store.state.selectedLayer)
  const qrContentType = computed(() => layer.value ? (store.getters.property(layer.value.id, 'qrContentType').value ?? 'url') : 'url')
  const qrContent = computed(() => layer.value ? (store.getters.property(layer.value.id, 'qrContent').value ?? '') : '')
  const readableContentType = computed(() => {
    const option = contentTypeOptions.find(option => option.value === qrContentType.value)
    return option ? option.name : 'Content'
  })

  const updateLayerProperty = (property, newValue, currentValueRef) => {
    if (props.disabled || !layer.value) {
      return
    }

    const undoActions = [
      {
        action: 'updateLayerProperty',
        payload: {
          layerId: layer.value.id,
          propertyName: property,
          propertyValue: currentValueRef.value,
        }
      }
    ]

    const redoActions = [
      {
        action: 'updateLayerProperty',
        payload: {
          layerId: layer.value.id,
          propertyName: property,
          propertyValue: newValue,
        }
      }
    ]

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: property,
      propertyValue: newValue,
    })

    setUndo({ undoActions, redoActions })
  }
</script>
