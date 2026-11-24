<template>
  <div :style="`height:${calculatedHeight}px; width:${calculatedWidth}px`" class="m-auto inset-0 absolute">
      <div class="shadow-lg">
        <LayoutEditor />
      </div>
      <EditorPreflightResults
        v-if="preflightChecked"
        class="mt-3"
        :issues="preflightIssues"
        @focus-issue="focusIssue"
      />
  </div>
</template>

<script setup>
  import { ref, computed, onMounted, onBeforeUnmount, nextTick, toRefs, watch } from 'vue'
  import { debounce } from 'lodash'
  import { useStore } from 'vuex'
  import LayoutEditor  from './LayoutEditor.vue'
  import EditorPreflightResults from './EditorPreflightResults.vue'
  import VueDragResize from 'vue-drag-resize'

  const props = defineProps({
    template: {
      type: Object,
      required: true,
    }
  })

  const store = useStore()
  const { template } = toRefs(props)

  store.commit('setTemplate', template.value)
  store.dispatch('updateGlobalFonts')
  const width = computed(() => Number(store.state.layoutWidth))
  const height = computed(() => Number(store.state.layoutHeight))


  const recalcZoom = debounce(() => nextTick(() => store.commit('updateZoom', calculateZoom())), 150)

  onMounted(() => {
    store.commit('updateZoom', calculateZoom())
    window.addEventListener('resize', recalcZoom)
    window.emitter.on('editor:saved', refreshPreflight)
    refreshPreflight()
  })

  onBeforeUnmount(() => {
    window.removeEventListener('resize', recalcZoom)
    window.emitter.off('editor:saved', refreshPreflight)
  })

  const calculatedZoom = computed(() => Number(store.state.calculatedZoom))
  
  const calculatedWidth = computed(() => store.getters.calculatedWidth)
  const calculatedHeight = computed(() => store.getters.calculatedHeight)
  const preflightIssues = ref([])
  const preflightChecked = ref(false)

  const refreshPreflight = () => axios
    .get(`/api/v1/templates/${template.value.uuid}/document/preflight`)
    .then(response => {
      preflightIssues.value = response.data.issues
      preflightChecked.value = true
    })
    .catch(() => {
      // An advisory check must not block editing or hide the canvas.
      preflightChecked.value = false
    })

  const focusIssue = path => {
    const match = /^\/layers\/(\d+)/.exec(path)
    const layer = match ? store.state.layers[Number(match[1])] : null

    if (layer) {
      store.commit('selectLayer', layer.id)
    }
  }

  watch(width, debounce(() => nextTick(() => store.commit('updateZoom', calculateZoom())), 500))
  watch(height, debounce(()=> nextTick(() => store.commit('updateZoom', calculateZoom())), 500))

  const calculateZoom = () => {
    const editorContainer = document.querySelector('#editor-container')
    if (! editorContainer) {
      return 1
    }
    if (width.value < editorContainer.clientWidth && height.value < editorContainer.clientHeight) {
      return 1
    }

    return width.value > height.value
      ? maxWidthZoom(editorContainer)
      : maxHeightZoom(editorContainer)
  }

  const maxWidthZoom = editorContainer => (editorContainer.clientWidth - 20) / width.value

  const maxHeightZoom = editorContainer => (editorContainer.clientHeight - 20) / height.value

</script>
