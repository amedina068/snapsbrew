<template>
  <div class="flex items-center">
    <button
      v-if="!isEditing"
      type="button"
      class="group inline-flex items-center space-x-2 rounded-md px-3 py-1.5 hover:bg-gray-100 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
      @click="startEditing"
    >
      <span class="text-base font-semibold text-gray-900">
        {{ displayValue }}
      </span>
      <PencilIcon class="h-4 w-4 text-gray-400 group-hover:text-gray-500" aria-hidden="true" />
    </button>
    <div v-else class="relative">
      <input
        ref="inputRef"
        v-model="draft"
        type="text"
        class="w-64 rounded-md border border-indigo-500 px-3 py-1.5 text-base font-semibold text-gray-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
        @blur="finishEditing"
        @keydown.enter.prevent="finishEditing"
        @keydown.esc.prevent="cancelEditing"
      >
    </div>
  </div>
</template>

<script setup>
import { PencilIcon } from '@heroicons/vue/24/outline'
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: String,
    required: true
  }
})

const emit = defineEmits(['update:modelValue'])

const isEditing = ref(false)
const draft = ref(props.modelValue)
const inputRef = ref(null)
const displayValue = computed(() => props.modelValue)

const startEditing = () => {
  draft.value = props.modelValue
  isEditing.value = true
  nextTick(() => {
    inputRef.value?.focus()
    inputRef.value?.select()
  })
}

const finishEditing = () => {
  const trimmed = draft.value.trim()
  if (trimmed && trimmed !== props.modelValue) {
    emit('update:modelValue', trimmed)
  } else if (!trimmed) {
    draft.value = props.modelValue
  }
  isEditing.value = false
}

const cancelEditing = () => {
  draft.value = props.modelValue
  isEditing.value = false
}

watch(
  () => props.modelValue,
  value => {
    if (!isEditing.value) {
      draft.value = value
    }
  }
)
</script>
