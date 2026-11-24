<template>
  <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-slate-50 p-3">
    <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-lg bg-white ring-1 ring-gray-200">
      <img v-if="image" :src="image" alt="Preview of the chosen image" class="size-full object-cover">
      <PhotographIcon v-else class="size-7 text-gray-400" aria-hidden="true" />
    </span>
    <div class="min-w-0">
      <label
        :for="id"
        class="inline-flex cursor-pointer items-center rounded-full border border-gray-300 bg-white px-4 py-1.5 text-sm font-semibold text-gray-800 hover:bg-slate-100 focus-within:outline-3 focus-within:outline-offset-2 focus-within:outline-atomic-coral"
      >
        {{ image ? 'Change image' : 'Choose image' }}
        <input @change="previewImage" :id="id" name="file-upload" type="file" class="sr-only">
      </label>
      <p class="mt-1 text-xs text-gray-500">PNG, JPG or GIF, up to 10 MB.</p>
    </div>
  </div>
</template>

<script setup>
  import { ref } from 'vue'
  import { PhotographIcon } from '@heroicons/vue/outline'

  const props = defineProps({
    id: {
      type: String,
      required: true
    }
  })

  const image = ref(null)

  const previewImage = () => {
    const input = document.getElementById(props.id) 
    if (! input.files.length) {
      return image.value = null
    }
    image.value = URL.createObjectURL(input.files[0])
  }
</script>