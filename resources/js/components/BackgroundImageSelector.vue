<template>
  <div>
    <div>
      <button
        type="button"
        @click.prevent="openBackgroundMediaLibrary"
        class="relative h-36 w-full flex items-center justify-center bg-gray-50 border border-gray-100 rounded-lg p-2 focus:outline-hidden focus:ring-2 focus:ring-rose-500 focus:border-rose-500 transition"
      >
        <template v-if="value">
          <img
            :src="value"
            alt="Selected background"
            class="w-full h-full object-contain rounded-md"
          >
        </template>
        <template v-else>
          <span class="bg-white flex justify-center px-2.5 py-1 rounded-md shadow-xs border border-gray-200 text-gray-700 hover:text-gray-800 transition-all text-xs font-medium">
            Add Image
          </span>
        </template>
      </button>
    </div>
    <div v-if="value" class="mt-2 text-right">
      <button
        type="button"
        @click.prevent="removeImage"
        class="text-xs font-medium text-rose-600 hover:text-rose-700 underline"
      >
        Remove image
      </button>
    </div>
  </div>
</template>

<script setup>
  import { onMounted, toRefs } from 'vue'

  const props = defineProps({
    value: {
      type: String,
      required: false,
    }
  })
  const emit = defineEmits(['updatedImage'])

  const { value } = toRefs(props)

  onMounted(() => window.emitter.on('imageSelected', src => emit('updatedImage', src)))
  const openBackgroundMediaLibrary = () => window.emitter.emit('openBackgroundMediaLibrary')
  const removeImage = () => emit('updatedImage', null)
</script>
