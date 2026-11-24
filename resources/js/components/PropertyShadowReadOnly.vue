<template>
  <div>
    <div class="flex justify-between">
      <h4>shadow</h4>
    </div>   
    <div class="mt-2" v-if="layerHasShadow">
      <div class="grid grid-cols-4 gap-x-4 mt-2">
        <div class="w-full inline-flex items-center justify-center bg-gray-100 text-center h-8 shadow-sm rounded-sm">{{ shadowX }}</div>
        <div class="w-full inline-flex items-center justify-center bg-gray-100 text-center h-8 shadow-sm rounded-sm">{{ shadowY }}</div>
        <div class="w-full inline-flex items-center justify-center bg-gray-100 text-center h-8 shadow-sm rounded-sm">{{ shadowBlur }}</div>
        <div class="w-full inline-flex items-center justify-center bg-gray-100 text-center h-8 shadow-sm rounded-sm">{{ shadowSpread }}</div>
      </div>
      <div class="mt-2">
        <div class="border-2 border-gray-200 h-6 w-6 rounded-sm flex items-center justify-center">
          <span class="w-5 h-5" :style="{background:  shadowColor}"></span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)
  const layerHasShadow = computed(() => Object.keys(store.getters.property(layer.value.id, 'shadow-sm')).length)
  const shadow = computed(() => store.getters.property(layer.value.id, 'shadow-sm').value)
  const shadowX = computed(() => shadow.value ? shadow.value.x : null)
  const shadowY = computed(() => shadow.value ? shadow.value.y : null)
  const shadowBlur = computed(() => shadow.value ? shadow.value.blur : null)
  const shadowSpread = computed(() => shadow.value ? shadow.value.spread : null)
  const shadowColor = computed(() => shadow.value ? shadow.value.color : null)
</script>