<template>
  <div>
    <div v-show="snap" class="relative" :style="{height: calculatedHeight+'px', width: calculatedWidth+'px'}">
      <img :src="snap" alt="">
      <div class="absolute inset-0 flex flex-col items-center justify-center m-auto h-64 w-64">
        <a :href="snap" class="text-gray-800 h-16 w-16 rounded-full inline-block rounded-sm bg-gray-100 flex items-center justify-center" download>
          <DownloadIcon class="h-8 w-8" aria-hidden="true" />
        </a>
        <a href="#" class="mt-4" @click.prevent="removeSnap">keep editing</a>
      </div>
    </div>
    <div
      v-show="! snap"
      id="editor-content"
      class="overflow-hidden bg-white relative"
      :style="{height: calculatedHeight+'px', width: calculatedWidth+'px', background: layoutBackground}"
    >
      <LayerBlock :key="layer.id" :layer="layer" v-for="layer in layers"/>
    </div>
  </div>
</template>

<script setup>
  import { DownloadIcon } from '@heroicons/vue/outline'
  import { computed, watch } from 'vue'
  import { useStore } from 'vuex'
  import LayerBlock from './LayerBlock.vue'

  const store = useStore()
  const snap = computed(() => store.state.snap)
  // A layer paints on the canvas only when neither it nor its group is hidden —
  // the group check mirrors the render path's group skip (SnapBuilder).
  const layers = computed(() => store.state.layers.filter(layer => ! store.getters.isLayerHidden(layer)))
  const layoutBackground = computed(() => store.state.layoutBackground)

  const calculatedWidth = computed(() => store.getters.calculatedWidth)
  const calculatedHeight = computed(() => store.getters.calculatedHeight)

  const removeSnap = () => store.commit('removeSnap')
</script>