<template>
  <div class="flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xs">
    <div class="relative bg-gray-50">
      <div v-if="hasTemplates" class="overflow-hidden">
        <div
          class="flex transition-transform duration-300 ease-out"
          :style="{ transform: `translateX(-${activeIndex * 100}%)` }"
        >
          <div
            v-for="template in templates"
            :key="template.uuid"
            class="w-full shrink-0"
          >
            <a :href="collectionLink" class="block">
              <img :src="template.preview_image" class="h-60 w-full bg-white object-cover" :alt="template.title">
            </a>
          </div>
        </div>
      </div>
      <div
        v-else
        class="flex h-60 items-center justify-center bg-white text-sm text-gray-500"
      >
        No templates yet
      </div>

      <button
        v-if="hasTemplates && templates.length > 1"
        type="button"
        class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 text-gray-600 shadow-sm hover:bg-white hover:text-gray-800 focus:outline-hidden"
        @click="prev"
      >
        <ChevronLeftIcon class="h-4 w-4" aria-hidden="true" />
      </button>
      <button
        v-if="hasTemplates && templates.length > 1"
        type="button"
        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/90 p-2 text-gray-600 shadow-sm hover:bg-white hover:text-gray-800 focus:outline-hidden"
        @click="next"
      >
        <ChevronRightIcon class="h-4 w-4" aria-hidden="true" />
      </button>

      <div
        v-if="hasTemplates && templates.length > 1"
        class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1"
      >
        <span
          v-for="(template, idx) in templates"
          :key="template.uuid"
          class="h-2 w-2 rounded-full"
          :class="idx === activeIndex ? 'bg-white' : 'bg-white/50'"
        ></span>
      </div>
    </div>

    <div class="flex flex-1 flex-col justify-between border-t border-gray-100 p-4">
      <div>
        <a :href="collectionLink" class="text-base font-semibold text-gray-900 hover:text-indigo-600">
          {{ collection.name }}
        </a>
        <p class="mt-1 text-xs text-gray-500">
          {{ templatesCount }} templates
        </p>
      </div>

      <div class="mt-4 text-sm text-gray-500">
        {{ collection.uuid }}
      </div>
    </div>
  </div>
</template>

<script setup>
  import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'

const props = defineProps({
  collection: {
    type: Object,
    required: true
  }
})

const templates = computed(() => props.collection.templates || [])
const templatesCount = computed(() => props.collection.templates_count ?? templates.value.length)
const hasTemplates = computed(() => templates.value.length > 0)
const activeIndex = ref(0)

watch(
  () => templates.value.length,
  () => {
    activeIndex.value = 0
  }
)

const collectionLink = computed(() => `/collections/${props.collection.uuid}`)

const prev = () => {
  activeIndex.value = activeIndex.value === 0
    ? templates.value.length - 1
    : activeIndex.value - 1
}

const next = () => {
  activeIndex.value = (activeIndex.value + 1) % templates.value.length
}
</script>
