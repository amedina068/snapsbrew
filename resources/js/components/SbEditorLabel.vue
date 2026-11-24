<template>
  <label class="block text-xs font-medium text-slate-600" :for="for">
    <slot />
  </label>
</template>

<script setup>
  const props = defineProps({
    for: {
      type: String,
      required: true,
    }
  })

</script>