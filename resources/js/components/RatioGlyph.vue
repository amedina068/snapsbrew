<template>
  <svg :width="box" :height="box" :viewBox="`0 0 ${box} ${box}`" class="shrink-0">
    <rect
      :x="(box - rectWidth) / 2"
      :y="(box - rectHeight) / 2"
      :width="rectWidth"
      :height="rectHeight"
      rx="1.5"
      :fill="active ? '#e0e7ff' : '#f1f5f9'"
      :stroke="active ? '#4f46e5' : '#64748b'"
      stroke-width="1.5"
    />
  </svg>
</template>

<script setup>
  import { computed } from 'vue'

  const props = defineProps({
    ratio: { type: Number, default: 1 },
    active: { type: Boolean, default: false },
  })

  const box = 22
  const span = box - 6 // 3px padding each side
  const safeRatio = computed(() => (props.ratio && isFinite(props.ratio) ? props.ratio : 1))
  const rectWidth = computed(() => (safeRatio.value >= 1 ? span : span * safeRatio.value))
  const rectHeight = computed(() => (safeRatio.value >= 1 ? span / safeRatio.value : span))
</script>
