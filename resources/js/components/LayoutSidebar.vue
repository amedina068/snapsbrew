<template>
  <div class="flex h-full w-64 max-w-sm flex-col border-r border-slate-200 bg-white">
    <header class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
      <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Layers</h3>
      <span v-if="hasLayers" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
        {{ layers.length }}
      </span>
    </header>

    <div class="flex-1 overflow-y-auto">
      <LayersSettings v-if="hasLayers" />
      <div v-else class="px-4 py-10">
        <div class="flex items-start gap-3 rounded-xl border border-slate-200 border-l-4 border-l-indigo-300 bg-white p-4">
          <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-indigo-600">
            <PlusIcon class="size-5" aria-hidden="true" />
          </span>
          <div>
            <p class="text-sm font-semibold text-slate-900">No layers yet</p>
            <p class="mt-1 text-xs text-slate-600">
              Use the Add layer menu in the top bar to start building your template.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { PlusIcon } from '@heroicons/vue/24/outline'
import { computed } from 'vue'
import { useStore } from 'vuex'
import LayersSettings from './LayersSettings.vue'

const store = useStore()
const snapToGrid = computed(() => store.state.snapToGrid)
const layers = computed(() => store.state.layers)
const hasLayers = computed(() => layers.value.length > 0)

const updateSnapToGrid = event => store.commit('updateSnapToGrid', event.target.checked)
</script>
