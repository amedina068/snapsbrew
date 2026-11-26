<template>
  <select 
    @change="change"
    :id="id"
    class="mt-1 block w-full rounded-md border border-slate-200 bg-white py-1.5 pl-3 pr-10 text-xs text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
  >
    <option
      :key="option[optionKey]"
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

  const { value, optionKey } = toRefs(props)

  // Compare with the key that the option uses for its value. The font family
  // select uses the key "family". A compare of option.value matched no font,
  // and the browser then showed the first font of the list.
  const isSelected = option => option[optionKey.value] == value.value

  const change = e => emit('change', e.target.value)
</script>