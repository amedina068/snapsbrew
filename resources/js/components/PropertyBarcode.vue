<template>
  <div>
    <div>
      <sb-editor-label for="barcode_format">Symbology</sb-editor-label>
      <div class="mt-1">
        <template v-if="props.disabled">
          <div class="px-2 py-1 bg-gray-100 rounded-sm text-xs font-semibold uppercase tracking-wide text-gray-600">
            {{ barcodeFormat }}
          </div>
        </template>
        <template v-else>
          <sb-editor-select
            id="barcode_format"
            :value="barcodeFormat"
            :options="formatOptions"
            @change="value => updateLayerProperty('barcodeFormat', value, barcodeFormatRef)"
          />
        </template>
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="barcode_content">Content</sb-editor-label>
      <p class="mt-1 text-xs text-gray-500">{{ activeRule.hint }}</p>
      <div class="mt-1">
        <template v-if="props.disabled">
          <div class="px-2 py-1 bg-gray-50 rounded-sm border border-gray-200 text-xs text-gray-600 min-h-[40px] wrap-break-word">
            {{ barcodeContent || 'No default content' }}
          </div>
        </template>
        <template v-else>
          <input
            id="barcode_content"
            type="text"
            class="block w-full rounded-sm border bg-white px-2 py-1 text-sm focus:outline-hidden focus:ring-2 focus:ring-indigo-500"
            :class="isValid ? 'border-gray-300' : 'border-[3px] border-red-600'"
            :value="barcodeContent"
            :aria-invalid="(!isValid).toString()"
            :aria-describedby="isValid ? null : 'barcode_content_error'"
            @input="event => updateLayerProperty('barcodeContent', event.target.value, barcodeContentRef)"
          />
          <p v-if="!isValid" id="barcode_content_error" class="mt-1 text-xs font-semibold text-red-600">
            {{ activeRule.error }}
          </p>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { computed, watch } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({
    disabled: {
      type: Boolean,
      default: false,
    }
  })

  const store = useStore()

  // Symbology validity is checked client-side on input — no server round-trip —
  // so the user sees a broken barcode before render, never after a 422.
  const formatRules = {
    'Code128': {
      label: 'Code 128',
      hint: 'Any printable ASCII characters.',
      error: 'Code 128 needs at least one printable character.',
      test: value => value.length > 0 && /^[\x20-\x7E]+$/.test(value),
    },
    'EAN-13': {
      label: 'EAN-13',
      hint: '12 or 13 digits.',
      error: 'EAN-13 needs 12 or 13 digits.',
      test: value => /^\d{12,13}$/.test(value),
    },
    'UPC-A': {
      label: 'UPC-A',
      hint: '12 digits.',
      error: 'UPC-A needs exactly 12 digits.',
      test: value => /^\d{12}$/.test(value),
    },
  }

  const formatOptions = Object.entries(formatRules).map(([value, rule]) => ({ value, name: rule.label }))

  const layer = computed(() => store.state.selectedLayer)
  const setUndo = payload => store.commit('setUndo', payload)

  const barcodeFormat = computed(() => layer.value ? (store.getters.property(layer.value.id, 'barcodeFormat').value ?? 'Code128') : 'Code128')
  const barcodeContent = computed(() => layer.value ? (store.getters.property(layer.value.id, 'barcodeContent').value ?? '') : '')
  // updateLayerProperty wants a ref-like { value } for the undo snapshot.
  const barcodeFormatRef = computed(() => ({ value: barcodeFormat.value }))
  const barcodeContentRef = computed(() => ({ value: barcodeContent.value }))

  const activeRule = computed(() => formatRules[barcodeFormat.value] ?? formatRules['Code128'])
  const isValid = computed(() => activeRule.value.test(String(barcodeContent.value ?? '')))

  // Hoist validity into the store so Save/Render can block on a broken barcode.
  // (The panel mounts via `<component :is>`, which swallows emits, so we commit
  // directly rather than emit.)
  watch([isValid, () => layer.value?.id], ([valid, id]) => {
    if (id) {
      store.commit('setLayerValidity', { layerId: id, valid })
    }
  }, { immediate: true })

  const updateLayerProperty = (property, newValue, currentValueRef) => {
    if (props.disabled || !layer.value) {
      return
    }

    const undoActions = [{
      action: 'updateLayerProperty',
      payload: { layerId: layer.value.id, propertyName: property, propertyValue: currentValueRef.value },
    }]
    const redoActions = [{
      action: 'updateLayerProperty',
      payload: { layerId: layer.value.id, propertyName: property, propertyValue: newValue },
    }]

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: property,
      propertyValue: newValue,
    })

    setUndo({ undoActions, redoActions })
  }
</script>
