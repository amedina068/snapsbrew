<template>
  <UiModal
    :open="open"
    layer="z-110"
    title="Unsaved changes"
    :dismissible="! busy"
    @close="busy ? null : onCancel()"
  >
    <template #icon>
      <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-50 text-amber-700">
        <ExclamationTriangleIcon class="size-5" aria-hidden="true" />
      </span>
    </template>

    <p class="text-sm leading-relaxed text-gray-600">
      Your changes to this template are not saved yet. Save them before you go, or leave without saving.
    </p>

    <template #footer>
      <UiButton variant="ghost" :disabled="busy" @click="onCancel">Keep editing</UiButton>
      <UiButton variant="danger-ghost" :disabled="busy" @click="onLeave">Leave without saving</UiButton>
      <UiButton :disabled="busy" @click="onSave">
        <span v-if="busy" class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
        {{ busy ? 'Saving…' : 'Save & leave' }}
      </UiButton>
    </template>
  </UiModal>
</template>

<script setup>
  import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
  import UiButton from './ui/UiButton.vue'
  import UiModal from './ui/UiModal.vue'

  defineProps({
    open: {
      type: Boolean,
      default: false,
    },
    busy: {
      type: Boolean,
      default: false,
    },
  })

  const emit = defineEmits(['save', 'leave', 'cancel'])

  const onSave = () => emit('save')
  const onLeave = () => emit('leave')
  const onCancel = () => emit('cancel')
</script>
