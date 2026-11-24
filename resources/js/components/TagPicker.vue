<template>
  <div class="space-y-4">
    <div>
      <label class="sr-only" for="tag-search">Search tags</label>
      <div class="relative">
        <input
          id="tag-search"
          type="text"
          v-model="query"
          @input="handleInput"
          @keydown.enter.prevent="handleEnter"
          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-xs focus:border-indigo-500 focus:outline-hidden focus:ring-2 focus:ring-indigo-200"
          :placeholder="placeholder"
        >
        <div v-if="loading" class="absolute inset-y-0 right-3 flex items-center">
          <svg class="h-4 w-4 animate-spin text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v2A6 6 0 006 12H4z"></path>
          </svg>
        </div>
      </div>
      <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
    </div>

    <div class="flex flex-wrap gap-2">
      <span
        v-for="tag in selectedTags"
        :key="tag.id"
        class="inline-flex items-center rounded-full bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-700"
      >
        {{ tag.name }}
        <button
          type="button"
          class="ml-2 text-indigo-500 hover:text-indigo-700 focus:outline-hidden"
          @click="removeTag(tag.id)"
          aria-label="Remove tag"
        >
          &times;
        </button>
      </span>
      <span v-if="!selectedTags.length" class="text-xs text-gray-500">No tags selected yet.</span>
    </div>

    <div v-if="showDropdown" class="max-h-56 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg">
      <button
        v-for="tag in filteredSuggestions"
        :key="tag.id"
        type="button"
        class="flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-indigo-50"
        @click="selectTag(tag)"
      >
        <span>{{ tag.name }}</span>
      </button>
      <button
        v-if="canCreateTag"
        type="button"
        class="flex w-full items-center justify-between px-4 py-2 text-left text-sm text-indigo-600 hover:bg-indigo-50"
        @click="createTag"
      >
        <span>Create “{{ query.trim() }}”</span>
      </button>
      <p v-if="!filteredSuggestions.length && !canCreateTag" class="px-4 py-2 text-sm text-gray-500">No matching tags.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  },
  placeholder: {
    type: String,
    default: 'Search or create tags'
  }
})

const emit = defineEmits(['update:modelValue'])

const query = ref('')
const suggestions = ref([])
const loading = ref(false)
const error = ref(null)
const selectedTags = ref([])

let debounceTimer = null

const filteredSuggestions = computed(() => {
  const selectedIds = selectedTags.value.map(tag => tag.id)
  return suggestions.value.filter(tag => !selectedIds.includes(tag.id))
})

const showDropdown = computed(() => query.value.trim() !== '' || filteredSuggestions.value.length > 0)

const canCreateTag = computed(() => {
  const name = query.value.trim()
  if (!name) {
    return false
  }

  const lowerName = name.toLowerCase()
  const existsInSelected = selectedTags.value.some(tag => tag.name.toLowerCase() === lowerName)
  const existsInSuggestions = suggestions.value.some(tag => tag.name.toLowerCase() === lowerName)

  return !existsInSelected && !existsInSuggestions
})

watch(
  () => props.modelValue,
  value => {
    selectedTags.value = Array.isArray(value) ? [...value] : []
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
})

function emitChange() {
  emit('update:modelValue', [...selectedTags.value])
}

function handleInput() {
  error.value = null

  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }

  const search = query.value.trim()

  if (search === '') {
    suggestions.value = []
    return
  }

  debounceTimer = setTimeout(() => {
    fetchSuggestions(search)
  }, 250)
}

function handleEnter() {
  if (canCreateTag.value) {
    createTag()
  } else if (filteredSuggestions.value.length === 1) {
    selectTag(filteredSuggestions.value[0])
  }
}

function fetchSuggestions(search) {
  loading.value = true
  error.value = null

  window.axios
    .get('/api/v1/tags', {
      params: {
        search
      }
    })
    .then(response => {
      suggestions.value = response.data.data || []
    })
    .catch(() => {
      error.value = 'Unable to load tags.'
    })
    .finally(() => {
      loading.value = false
    })
}

function selectTag(tag) {
  const exists = selectedTags.value.some(existing => existing.id === tag.id)
  if (!exists) {
    selectedTags.value = [...selectedTags.value, tag]
    emitChange()
  }
  query.value = ''
  suggestions.value = []
}

function removeTag(tagId) {
  selectedTags.value = selectedTags.value.filter(tag => tag.id !== tagId)
  emitChange()
}

function createTag() {
  const name = query.value.trim()
  if (!name) {
    return
  }

  loading.value = true
  error.value = null

  window.axios
    .post('/api/v1/tags', { name })
    .then(response => {
      const tag = response.data.data
      if (tag) {
        selectTag(tag)
      }
    })
    .catch(err => {
      if (err.response && err.response.data && err.response.data.message) {
        error.value = err.response.data.message
      } else {
        error.value = 'Unable to create tag.'
      }
    })
    .finally(() => {
      loading.value = false
      query.value = ''
    })
}
</script>
