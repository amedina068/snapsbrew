<template>
  <div ref="root" class="relative">
    <!-- Trigger -->
    <button
      ref="trigger"
      type="button"
      @click="toggle"
      class="mt-1 flex w-full items-center gap-2 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-left text-sm text-slate-700 transition-colors hover:border-slate-400 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500"
      :aria-expanded="open"
      aria-haspopup="listbox"
    >
      <span class="h-7 w-7 shrink-0 rounded-sm border border-slate-200 bg-slate-50" :style="swatch(value)"></span>
      <span class="min-w-0 flex-1 truncate">{{ currentLabel }}</span>
      <ChevronDownIcon class="h-4 w-4 shrink-0 text-slate-400 transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <!-- Dropdown (teleported so it floats above the scroll container) -->
    <Teleport to="body">
      <div
        v-if="open"
        ref="panel"
        class="fixed z-100 flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
        :style="menuStyle"
      >
        <div class="shrink-0 border-b border-slate-100 p-2">
          <input
            ref="searchInput"
            v-model="query"
            type="text"
            placeholder="Search patterns…"
            class="w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-sm text-slate-700 placeholder-slate-400 focus:border-indigo-400 focus:bg-white focus:outline-hidden focus:ring-0"
          >
        </div>

        <ul class="grid flex-1 grid-cols-4 gap-1.5 overflow-y-auto p-2" role="listbox">
          <li v-for="option in filtered" :key="option.value">
            <button
              type="button"
              @click="select(option.value)"
              :title="option.label"
              class="group flex w-full flex-col items-center gap-1 rounded-md border p-1.5 transition-colors"
              :class="option.value === value
                ? 'border-indigo-500 ring-1 ring-indigo-500'
                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'"
              role="option"
              :aria-selected="option.value === value"
            >
              <span class="h-12 w-full rounded-sm bg-slate-50" :style="swatch(option.value)"></span>
              <span class="w-full truncate text-center text-[11px] leading-tight text-slate-600">{{ option.label }}</span>
            </button>
          </li>
          <li v-if="! filtered.length" class="col-span-4 px-2 py-6 text-center text-xs text-slate-400">
            No patterns match “{{ query }}”.
          </li>
        </ul>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
  import { ChevronDownIcon } from '@heroicons/vue/outline'
  import { ref, computed, nextTick, onBeforeUnmount, toRefs } from 'vue'
  import SvgDesigns from '../libs/SvgDesigns'
  import SvgDesignOptions from '../libs/SvgDesignOptions'

  const PREVIEW_COLOR = '#64748b' // slate-500
  const MENU_WIDTH = 384
  const MENU_MAX_HEIGHT = 340
  const GAP = 6
  const VIEWPORT_PADDING = 8

  const props = defineProps({
    value: {
      type: String,
      required: true,
    },
  })
  const emit = defineEmits(['change'])
  const { value } = toRefs(props)

  const open = ref(false)
  const query = ref('')
  const root = ref(null)
  const trigger = ref(null)
  const panel = ref(null)
  const searchInput = ref(null)
  const menuStyle = ref({})

  const currentLabel = computed(() => {
    const match = SvgDesignOptions.find(option => option.value === value.value)
    return match ? match.label : value.value
  })

  const filtered = computed(() => {
    const term = query.value.trim().toLowerCase()
    if (! term) {
      return SvgDesignOptions
    }
    return SvgDesignOptions.filter(option => option.label.toLowerCase().includes(term))
  })

  const swatch = design => {
    const template = SvgDesigns[design]
    if (! template) {
      return {}
    }
    const url = template.replace('{svgColor}', encodeURIComponent(PREVIEW_COLOR))
    return { backgroundImage: `url("${url}")` }
  }

  const updatePosition = () => {
    const el = trigger.value
    if (! el) {
      return
    }
    const rect = el.getBoundingClientRect()
    const width = Math.max(rect.width, MENU_WIDTH)
    const left = Math.min(
      Math.max(rect.left, VIEWPORT_PADDING),
      window.innerWidth - width - VIEWPORT_PADDING
    )

    const spaceBelow = window.innerHeight - rect.bottom - GAP - VIEWPORT_PADDING
    const spaceAbove = rect.top - GAP - VIEWPORT_PADDING
    const openUp = spaceBelow < 280 && spaceAbove > spaceBelow

    const cap = space => `${Math.min(Math.max(space, 160), MENU_MAX_HEIGHT)}px`

    menuStyle.value = openUp
      ? { left: `${left}px`, bottom: `${window.innerHeight - rect.top + GAP}px`, width: `${width}px`, maxHeight: cap(spaceAbove) }
      : { left: `${left}px`, top: `${rect.bottom + GAP}px`, width: `${width}px`, maxHeight: cap(spaceBelow) }
  }

  const toggle = () => open.value ? close() : openMenu()

  const openMenu = () => {
    open.value = true
    updatePosition()
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)
    document.addEventListener('mousedown', onOutside)
    nextTick(() => {
      updatePosition()
      searchInput.value?.focus()
    })
  }

  const close = () => {
    open.value = false
    query.value = ''
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('resize', updatePosition)
    window.removeEventListener('scroll', updatePosition, true)
    document.removeEventListener('mousedown', onOutside)
  }

  const select = design => {
    emit('change', design)
    close()
  }

  const onKeydown = event => {
    if (event.key === 'Escape') {
      close()
    }
  }

  const onOutside = event => {
    const insideTrigger = root.value && root.value.contains(event.target)
    const insidePanel = panel.value && panel.value.contains(event.target)
    if (! insideTrigger && ! insidePanel) {
      close()
    }
  }

  onBeforeUnmount(close)
</script>
