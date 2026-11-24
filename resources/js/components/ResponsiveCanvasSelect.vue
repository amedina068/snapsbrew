<template>
  <label class="block text-sm font-medium text-gray-700">
    Canvas
    <select class="mt-1 block w-full" :value="modelValue" @change="$emit('update:modelValue', $event.target.value)">
      <option v-for="canvas in canvases" :key="canvas" :value="canvas">{{ canvas }}</option>
    </select>
  </label>
</template>

<script setup>
defineProps({ modelValue: { type: String, required: true }, canvases: { type: Array, required: true } })
defineEmits(['update:modelValue'])
</script>
