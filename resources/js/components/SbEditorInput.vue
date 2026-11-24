<template>
  <input
    :type="type"
    :id="id"
    @change="change"
    :class="[
      'block w-full text-xs rounded-md',
      disabled
        ? 'p-0 bg-white font-semibold border-none text-slate-800 cursor-default focus:ring-0 focus:border-none'
        : 'py-1.5 px-2.5 border border-slate-200 bg-white text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500'
    ]"
    :value="value"
    :disabled="disabled"
  />
</template>

<script setup>
  const props = defineProps({
    value: {
      required: true,
    },
    type: {
      type: String,
      required: true,
    },
    id: {
      type: String,
      required: true
    },
    disabled: {
      type: Boolean,
      default: false,
    }
  })

  const emit = defineEmits([ 'change' ])

  const change = e => {
    if (props.disabled) {
      return
    }

    emit('change', e.target.value)
  }
</script>
