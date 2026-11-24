<template>
    <div class="relative py-6 px-4 h-screen">
        <div v-if="hasLatestSnaps" class="mb-8">
            <div class="grid gap-6 sm:grid-cols-2">
                <div v-for="snap in latestSnaps" :key="snap.id" class="flex flex-col items-center space-y-3">
                    <a :href="snap.media_url" target="_blank">
                        <img v-if="snap.type == 'image'" :src="snap.media_url" alt="Generated snap" class="mx-auto max-w-sm rounded-lg border border-gray-200">
                        <span v-else class="font-semibold text-rose-600 underline">Download PDF</span>
                    </a>
                    <p class="text-sm text-gray-600">{{ snap.template_title ?? '' }}</p>
                </div>
            </div>
            <div class="mt-4">
                <p class="text-sm text-center text-gray-700 font-semibold">You can find these Snaps in your Snapsbrew account.</p>
            </div>
        </div>
        <div>
            <h2 class="text-2xl font-semibold text-gray-900">{{ form.title }}</h2>
        </div>
        <form @submit.prevent="submitForm">
            <div class="mt-8 space-y-5 flex flex-col">
                <div v-for="layer in layers" :key="layer.key">
                    <SbEmbeddedFormLabel :for="`input_${layer.key}`">
                        {{ layer.label ?? layer.key }}
                        <span
                            v-if="layer.type == 'qr_code' && layer.content_type"
                            class="ml-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400"
                        >
                            ({{ layer.content_type }})
                        </span>
                    </SbEmbeddedFormLabel>
                    <div class="mt-2">
                        <SbEmbeddedFormColorPicker
                            v-if="layer.type == 'empty'"
                            :id="`input_${layer.key}`"
                            :value="layer.default_value"
                        />
                        <SbEmbeddedFormInput
                            v-if="layer.type == 'text'"
                            type="text"
                            :placeholder="layer.default_value"
                            :id="`input_${layer.key}`"
                        />
                        <SbEmbeddedFormInput
                            v-if="layer.type == 'qr_code'"
                            :type="layer.content_type === 'url' ? 'url' : 'text'"
                            :placeholder="layer.default_value"
                            :id="`input_${layer.key}`"
                        />
                        <SbEmbeddedFormInput
                            v-if="layer.type == 'barcode'"
                            type="text"
                            :placeholder="layer.default_value"
                            :id="`input_${layer.key}`"
                        />
                        <SbEmbeddedFormFileInput
                            v-if="layer.type == 'image'"
                            :id="`input_image_${layer.key}`"
                        />
                    </div>
                </div>    
            </div>
            <div class="mt-5">
                <UiButton type="submit" :disabled="submitting">Submit</UiButton>
            </div>
        </form>
        <div v-show="submitting" class="absolute inset-0 flex h-screen w-full items-center justify-center bg-white/70" role="status">
            <span class="inline-flex items-center gap-3 rounded-full bg-white px-5 py-3 text-lg font-semibold text-rose-700 ring-1 ring-gray-200">
                <span class="size-5 animate-spin rounded-full border-2 border-rose-200 border-t-rose-600" aria-hidden="true"></span>
                Generating snap
            </span>
        </div>
    </div>
</template>

<script setup>
    import { computed, ref, toRefs } from 'vue'
    import SbEmbeddedFormLabel from './SbEmbeddedFormLabel'
    import SbEmbeddedFormColorPicker from './SbEmbeddedFormColorPicker'
    import SbEmbeddedFormInput from './SbEmbeddedFormInput'
    import SbEmbeddedFormFileInput from './SbEmbeddedFormFileInput'
    import UiButton from './ui/UiButton.vue'

    const props = defineProps({
        form: {
            type: Object,
            required: true
        },
        layers: {
            type: Array,
            required: true
        }
    })

    const submitting = ref(false)
    const latestSnaps = ref([])

    const { layers, form } = toRefs(props)
    const hasLatestSnaps = computed(() => latestSnaps.value.length > 0)
    const submitForm = () => {
        submitting.value = true
        latestSnaps.value = []

        const formData = new FormData
        layers.value.filter(layer => layer.type == 'text' || layer.type == 'empty' || layer.type == 'qr_code' || layer.type == 'barcode')
          .forEach(layer => {
            const input =document.getElementById(`input_${layer.key}`)
            if (! input.value) {
                return
            }
            formData.append(`layers[${layer.key}]`, input.value)
          })

        layers.value.filter(layer => layer.type == 'image')
          .forEach(layer => {
            const input = document.getElementById(`input_image_${layer.key}`)
            if (! input.files.length) {
                return
            }
            formData.append(`layers[${layer.key}]`, input.files[0])
          })

        axios.post(form.value.submit_signed_url, formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        }).then(({ data }) => {
          latestSnaps.value = normalizeResponse(data)
        }).finally(() => submitting.value = false)
    }

    const normalizeResponse = (data) => {
        if (! data) {
            return []
        }

        if (Array.isArray(data)) {
            return data
        }

        if (Array.isArray(data.snaps)) {
            return data.snaps
        }

        if (Array.isArray(data.data)) {
            return data.data
        }

        if (data.data) {
            return [data.data]
        }

        return [data]
    }
</script>
