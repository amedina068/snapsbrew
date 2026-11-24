<template>
  <div>border</div>
  <div>
    <span>{{ borderStyle }}</span>
  </div>
  <div v-show="borderStyle != 'none'">
    width
    <div class="w-full inline-flex items-center justify-center text-center bg-gray-100 h-8 shadow-sm rounded-sm">{{ borderWidth }}</div>
  </div>
  <div v-show="borderStyle != 'none'">
    color
    <div class="border-2 border-gray-200 h-6 w-6 rounded-sm flex items-center justify-center">
      <span class="w-5 h-5" :style="{background:  borderColor}"></span>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)

  const borderStyle = computed(() => store.getters.property(layer.value.id, 'borderStyle').value)
  const borderWidth = computed(() => store.getters.property(layer.value.id, 'borderWidth').value)
  const borderColor = computed(() => store.getters.property(layer.value.id, 'borderColor').value)
</script>