<template>
  <div>
    <div class="flex items-center justify-between gap-2">
      <sb-editor-label for="auto_resize">Auto-resize text</sb-editor-label>
      <button
        id="auto_resize"
        type="button"
        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
        :class="autoResize ? 'bg-indigo-600' : 'bg-slate-300'"
        :aria-pressed="autoResize.toString()"
        aria-label="Auto-resize text to fit its box"
        :disabled="props.disabled"
        @click="toggle"
      >
        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform" :class="autoResize ? 'translate-x-4' : 'translate-x-0.5'" />
      </button>
    </div>
    <p v-if="autoResize" class="mt-2 rounded-sm bg-slate-50 px-2 py-1 text-xs text-slate-500">
      Font size is auto-managed — the text shrinks to fit its box at render time.
    </p>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({ disabled: { type: Boolean, default: false } })
  const store = useStore()
  const setUndo = payload => store.commit('setUndo', payload)

  const layer = computed(() => store.state.selectedLayer)
  const autoResize = computed(() => store.getters.property(layer.value.id, 'autoResize').value === true)

  const toggle = () => {
    if (props.disabled) {
      return
    }
    const next = ! autoResize.value
    setUndo({
      undoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'autoResize', propertyValue: autoResize.value } }],
      redoActions: [{ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'autoResize', propertyValue: next } }],
    })
    store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'autoResize', propertyValue: next })
  }
</script>
