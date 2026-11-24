<template>
  <UiModal :open="open" size="xl" title="Media library" description="Pick an image for the layer, or upload a new one." @close="open = false">
    <ul class="grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto p-1 sm:grid-cols-4">
      <li>
        <label
          for="upload-files"
          class="flex h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl bg-slate-50 text-sm font-semibold text-gray-700 ring-2 ring-slate-200 transition-colors hover:bg-rose-50 hover:text-rose-700 focus-within:outline-3 focus-within:outline-offset-2 focus-within:outline-atomic-coral"
        >
          <PlusIcon class="size-6" aria-hidden="true" />
          Add images
          <input id="upload-files" @change="uploadMedia" type="file" class="sr-only">
        </label>
      </li>
      <li v-for="image in images" :key="image.id">
        <button
          type="button"
          class="focus-ring block h-36 w-full overflow-hidden rounded-xl bg-slate-50 ring-1 ring-slate-200 hover:ring-2 hover:ring-rose-400"
          aria-label="Use this image"
          @click.prevent="selectImage(image.src)"
        >
          <img :src="image.src" alt="" class="size-full object-contain">
        </button>
      </li>
    </ul>
  </UiModal>
</template>

<script>
import { onMounted, ref, watch } from 'vue'
import { PlusIcon } from '@heroicons/vue/outline'
import UiModal from './ui/UiModal.vue'

export default {
  components: {
    PlusIcon,
    UiModal,
  },

  setup() {
    const open = ref(false)
    const images = ref([])

    onMounted(() => {
      window.emitter.on('openBackgroundMediaLibrary', () => {
        open.value = true
        fetchImages()
      })
    })

    const selectImage = src => {
      window.emitter.emit('imageSelected', src)
      open.value = false
      images.value = []
    } 

    const fetchImages = () => axios.get('/api/v1/media').then(({data}) => images.value = data.data)

    const uploadMedia = event => {
      const files = event.target.files
      if (! files.length) {
        return
      }
      for(let x = 0; x < files.length; x++) {
        uploadFile(files[x])
      }
    }

    const uploadFile = file => {
      const formData = new FormData
      formData.append('file', file)
      axios.post('/api/v1/media', formData, {
        headers: {
          'Content-Type': 'multipart/form-data'
        }
      }).then(({data}) => images.value.unshift(data))
    }

    return {
      images,
      open,
      selectImage,
      uploadMedia
    }
  },
}
</script>