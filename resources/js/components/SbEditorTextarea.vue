<template>
  <textarea
    rows="4"
    :id="id"
    :value="value"
    @change="change"
    class="shadow-xs focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
  ></textarea>
</template>

<script setup>
  const props = defineProps({
    id: {
      type: String,
      required: true
    },
    value: {
      required: true
    }
  })

  const emit = defineEmits(['change'])

  const change = e => emit('change', e.target.value)
</script>