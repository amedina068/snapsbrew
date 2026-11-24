<template>
  <div class="w-full">
    <div v-if="layer" :key="layer.id">
      <header class="flex items-center justify-between gap-2 border-b border-black/5 px-4 py-3">
        <div class="flex min-w-0 items-center gap-2">
          <button
            @click.prevent="toggleLock"
            type="button"
            :title="layer.locked ? 'Unlock layer' : 'Lock layer'"
            class="flex size-7 shrink-0 items-center justify-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
          >
            <LockClosedIcon class="size-4" v-show="layer.locked" aria-hidden="true" />
            <LockOpenIcon class="size-4" v-show="! layer.locked" aria-hidden="true" />
          </button>
          <input
            :value="layer.key"
            @input="updateKey"
            :readonly="layer.locked"
            type="text"
            title="Layer name"
            class="min-w-0 flex-1 rounded-sm border border-transparent bg-transparent px-1.5 py-1 text-sm font-semibold text-slate-800 hover:border-slate-200 focus:border-indigo-300 focus:outline-hidden focus:ring-0 read-only:hover:border-transparent read-only:cursor-default"
          >
        </div>
        <div class="flex shrink-0 items-center gap-0.5" v-if="! layer.locked">
          <button
            :disabled="! hasUndo"
            @click.prevent="undo"
            type="button"
            title="Undo"
            class="flex size-7 items-center justify-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
          >
            <ReplyIcon class="size-4" aria-hidden="true" />
          </button>
          <button
            :disabled="! hasRedo"
            @click.prevent="redo"
            type="button"
            title="Redo"
            class="flex size-7 items-center justify-center rounded-md text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 disabled:pointer-events-none disabled:opacity-40 focus-visible:outline-solid focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
          >
            <ReplyIcon class="size-4 -scale-x-100" aria-hidden="true" />
          </button>
        </div>
      </header>
      <div>
        <div v-if="layer.locked" class="px-4">
          <LayerPropertiesReadOnly  />
        </div>
        
        <LayerPropertiesSettings v-if="! layer.locked" />  
      </div>
    </div>
    <div v-if="! layer" class="px-4 py-8">
      <div class="flex items-start gap-3 rounded-xl border border-slate-200 border-l-4 border-l-indigo-300 bg-white p-4">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-indigo-600">
          <CursorClickIcon class="size-5" aria-hidden="true" />
        </span>
        <div>
          <p class="text-sm font-semibold text-slate-900">No layer selected</p>
          <p class="mt-1 text-xs text-slate-600">
            Choose a layer from the list to edit its properties. You can also add a new layer from the Add layer menu in the top bar.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { CursorClickIcon, LockOpenIcon, ReplyIcon } from '@heroicons/vue/outline'
  import { LockClosedIcon } from '@heroicons/vue/solid'
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  import LayerPropertiesSettings from './LayerPropertiesSettings'
  import LayerPropertiesReadOnly from './LayerPropertiesReadOnly'

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)

  const updateLayer = (property, newValue) => store.commit('updateLayer', {
    name: property,
    layerId: layer.value.id,
    value: newValue
  })

  const updateKey = event => store.commit('updateLayer', {
    name: 'key',
    layerId: layer.value.id,
    value: event.target.value
  })

  const hasUndo = computed(() => store.state.undo.length)
  const hasRedo = computed(() => store.state.redo.length)

  const undo = () => store.dispatch('undo')
  const redo = () => store.dispatch('redo')

  const toggleLock = () => store.commit('updateLayer', {
    name: 'locked',
    layerId: layer.value.id,
    value: ! layer.value.locked
  })

</script>
