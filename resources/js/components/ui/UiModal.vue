<template>
  <!--
    The app dialog. Headless UI gives the focus trap, the Esc key, the
    scroll lock and the click outside. On a phone the panel sits at the
    bottom of the screen. On a wide screen it sits in the middle.
  -->
  <TransitionRoot appear :show="open" as="template">
    <Dialog as="div" :class="['relative', layer]" :initial-focus="initialFocus" @close="emit('close')">
      <TransitionChild
        as="template"
        enter="transition-opacity duration-150 ease-out"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="transition-opacity duration-100 ease-in"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-slate-950/50" aria-hidden="true" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
          <TransitionChild
            as="template"
            enter="transition duration-150 ease-out"
            enter-from="translate-y-4 opacity-0"
            enter-to="translate-y-0 opacity-100"
            leave="transition duration-100 ease-in"
            leave-from="opacity-100"
            leave-to="opacity-0"
          >
            <DialogPanel :class="[widths[size] ?? widths.md, 'w-full rounded-2xl border border-gray-200 bg-white text-left']">
              <header class="flex items-start gap-3 border-b border-gray-100 px-5 py-4">
                <slot name="icon" />
                <div class="min-w-0 flex-1">
                  <DialogTitle as="h2" class="text-base font-semibold text-gray-900">{{ title }}</DialogTitle>
                  <DialogDescription v-if="description" class="mt-0.5 text-sm text-gray-500">{{ description }}</DialogDescription>
                </div>
                <button
                  v-if="dismissible"
                  type="button"
                  class="focus-ring -mr-1 grid size-8 shrink-0 place-items-center rounded-full text-gray-500 hover:bg-slate-100 hover:text-gray-900"
                  @click="emit('close')"
                >
                  <span class="sr-only">Close</span>
                  <XIcon class="size-5" aria-hidden="true" />
                </button>
              </header>

              <div class="px-5 py-5">
                <slot />
              </div>

              <footer v-if="$slots.footer" class="flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-end">
                <slot name="footer" />
              </footer>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup>
import { Dialog, DialogDescription, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue'
import { XIcon } from '@heroicons/vue/outline'

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  description: { type: String, default: null },
  size: { type: String, default: 'md' },
  dismissible: { type: Boolean, default: true },
  // The stack level. The editor needs a higher level than its own panels.
  layer: { type: String, default: 'z-50' },
  initialFocus: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const widths = {
  sm: 'max-w-sm',
  md: 'max-w-md',
  lg: 'max-w-xl',
  xl: 'max-w-3xl',
}
</script>
