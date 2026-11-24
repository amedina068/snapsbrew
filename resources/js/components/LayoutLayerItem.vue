<template>
  <li>
    <div
      role="button"
      tabindex="0"
      @click="selectLayer"
      @keydown.enter="selectLayer"
      @keydown.space.prevent="selectLayer"
      class="group relative flex items-center gap-1 border-l-2 py-1.5 pl-3 pr-2 text-xs cursor-pointer transition-colors"
      :class="selected
        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
        : 'border-transparent text-slate-600 hover:bg-slate-50'"
    >
      <span class="layer-drag-handle cursor-grab text-slate-300 hover:text-slate-400 active:cursor-grabbing" :class="{ 'text-indigo-300 hover:text-indigo-400': selected }">
        <MenuIcon class="w-4 h-4" aria-hidden="true" />
      </span>
      <button @click.stop="toggleVisible" :title="layer.visible ? 'Hide layer' : 'Show layer'" class="shrink-0 rounded-sm p-0.5 transition-colors" :class="selected ? 'text-indigo-500 hover:text-indigo-700' : 'text-slate-400 hover:text-slate-600'">
        <EyeIcon class="h-4 w-4" v-show="layer.visible" aria-hidden="true" />
        <EyeOffIcon class="h-4 w-4" v-show="! layer.visible" aria-hidden="true" />
      </button>
      <span
        class="min-w-0 flex-1 truncate px-1.5 py-1 text-sm"
        :class="selected ? 'text-indigo-700' : 'text-slate-700'"
      >{{ layer.key }}</span>

      <!-- Inline delete confirmation -->
      <div v-if="confirmingDelete" class="ml-auto flex shrink-0 items-center gap-0.5">
        <button @click.prevent.stop="removeLayer" title="Confirm delete" class="rounded-sm p-1 text-red-500 hover:bg-red-50 hover:text-red-600">
          <CheckIcon class="h-4 w-4" aria-hidden="true" />
        </button>
        <button @click.prevent.stop="cancelDelete" title="Cancel" class="rounded-sm p-1 text-slate-400 hover:bg-white hover:text-slate-600">
          <XIcon class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>

      <div v-else class="ml-auto flex shrink-0 items-center gap-0.5 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100" :class="{ 'opacity-100': selected }">
        <button @click.prevent.stop="toggleLock" :title="locked ? 'Unlock layer' : 'Lock layer'" class="rounded-sm p-1 text-slate-400 hover:bg-white hover:text-slate-600">
          <LockClosedIcon class="h-4 w-4" v-show="locked" aria-hidden="true" />
          <LockOpenIcon class="h-4 w-4" v-show="! locked" aria-hidden="true" />
        </button>
        <button @click.prevent.stop="cloneLayer" title="Duplicate layer" class="rounded-sm p-1 text-slate-400 hover:bg-white hover:text-slate-600">
          <DuplicateIcon class="h-4 w-4" aria-hidden="true" />
        </button>
        <button @click.prevent.stop="askDelete" title="Delete layer" class="rounded-sm p-1 text-slate-400 hover:bg-white hover:text-red-500">
          <TrashIcon class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </div>
  </li>
</template>

<script setup>
  import { CheckIcon, DuplicateIcon, EyeIcon, EyeOffIcon, LockClosedIcon, LockOpenIcon, MenuIcon, TrashIcon, XIcon } from '@heroicons/vue/outline'
  import { toRefs, computed, ref } from 'vue'
  import { useStore } from 'vuex'

  const props = defineProps({
    layer: {
      type: Object,
      required: true,
    },
  })
  const store = useStore()
  const { layer } = toRefs(props)
  const confirmingDelete = ref(false)
  const selectedLayer = computed(() => store.state.selectedLayer)
  const layers = computed(() => store.state.layers)
  const locked = computed(() => layer.value.locked)
  const selected = computed(() => selectedLayer.value ? selectedLayer.value.id == layer.value.id : false)
  const selectLayer = () => {
    selected.value
      ? null
      : store.commit('selectLayer', layer.value.id)
  }

  const askDelete = () => confirmingDelete.value = true
  const cancelDelete = () => confirmingDelete.value = false

  const removeLayer = () => {
    const newLayers = layers.value.filter(listLayer => listLayer.id != layer.value.id)
    store.commit('selectLayer', null)
    store.commit('setLayers', newLayers)
    confirmingDelete.value = false
  }

  const toggleLock = () => store.commit('updateLayer', {
    name: 'locked',
    layerId: layer.value.id,
    value: ! locked.value
  })

  const toggleVisible = () => store.commit('updateLayer', {
    name: 'visible',
    layerId: layer.value.id,
    value: ! layer.value.visible
  })

  const cloneLayer = () => {
    const layerData = layers.value.find(layersLayer => layersLayer.id == layer.value.id)
    const newLayer = {
      ...layerData,
      id: `id-${parseInt(Math.random() * 1000)}`,
      title: `${layerData.title} clone`,
      key: `layer_${layers.value.length + 1}`,
      properties: layerData.properties.map(property => property.name != 'zIndex' ? property : {name: 'zIndex', value: layers.value.length + 1},)
    }
    store.commit('addLayer', newLayer)
    store.commit('selectLayer', newLayer.id)
  }

</script>