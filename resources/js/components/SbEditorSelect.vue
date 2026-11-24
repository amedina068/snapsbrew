<template>
  <select 
    @change="change"
    :id="id"
    class="mt-1 block w-full rounded-md border border-slate-200 bg-white py-1.5 pl-3 pr-10 text-xs text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
  >
    <option
      :key="option.value"
      v-for="option in options"
      :selected="isSelected(option)"
      :value="option[optionKey]"
    >{{ option[optionText] }}</option>
  </select>
</template>

<script setup>
  import { toRefs } from 'vue'
  const props = defineProps({
    id: {
      type: String,
      required: true,
    },
    options: {
      type: Array,
      required: true,
    },
    value: {
      required: true
    },
    optionKey: {
      type: String,
      required: false,
      default: 'value'
    },
    optionText: {
      type: String,
      required: false,
      default: 'name'
    },
  })

  const emit = defineEmits(['change'])

  const { value } = toRefs(props)

  const isSelected = option => option.value == value.value

  const change = e => emit('change', e.target.value)
</script>