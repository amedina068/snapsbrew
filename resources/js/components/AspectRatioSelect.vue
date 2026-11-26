<template>
  <div ref="root" class="relative">
    <button
      type="button"
      :disabled="disabled"
      class="flex w-full items-center justify-between gap-2 rounded-md border border-slate-200 bg-white py-1.5 pl-2 pr-2 text-xs text-slate-700 transition-colors hover:border-slate-300 focus:border-indigo-500 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
      @click.prevent="open = !open"
    >
      <span class="flex items-center gap-2">
        <ratio-glyph :ratio="active ? active.ratio : width / height" />
        <span>{{ active ? active.label : 'Custom' }}</span>
      </span>
      <ChevronDownIcon class="h-4 w-4 text-slate-400" :class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <div
      v-show="open"
      class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg"
    >
      <p class="px-2 pb-1 pt-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Horizontal</p>
      <button
        v-for="preset in horizontal"
        :key="preset.label"
        type="button"
        class="flex w-full items-center gap-2 px-2 py-1.5 text-left text-xs transition-colors hover:bg-slate-50"
        :class="isActive(preset) ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700'"
        @click.prevent="choose(preset)"
      >
        <ratio-glyph :ratio="preset.ratio" :active="isActive(preset)" />
        <span class="font-medium">{{ preset.label }}</span>
      </button>

      <p class="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Square</p>
      <button
        v-for="preset in square"
        :key="preset.label"
        type="button"
        class="flex w-full items-center gap-2 px-2 py-1.5 text-left text-xs transition-colors hover:bg-slate-50"
        :class="isActive(preset) ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700'"
        @click.prevent="choose(preset)"
      >
        <ratio-glyph :ratio="preset.ratio" :active="isActive(preset)" />
        <span class="font-medium">{{ preset.label }}</span>
      </button>

      <p class="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Vertical</p>
      <button
        v-for="preset in vertical"
        :key="preset.label"
        type="button"
        class="flex w-full items-center gap-2 px-2 py-1.5 text-left text-xs transition-colors hover:bg-slate-50"
        :class="isActive(preset) ? 'bg-indigo-50 text-indigo-600' : 'text-slate-700'"
        @click.prevent="choose(preset)"
      >
        <ratio-glyph :ratio="preset.ratio" :active="isActive(preset)" />
        <span class="font-medium">{{ preset.label }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
  import { ChevronDownIcon } from '@heroicons/vue/24/outline'
  import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
  import RatioGlyph from './RatioGlyph.vue'

  const props = defineProps({
    width: { type: Number, default: 0 },
    height: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
  })

  const emit = defineEmits(['select'])

  // Relative tolerance: ~0.5% of the preset ratio. Absorbs pixel rounding
  // (e.g. 16:9 of 1000px -> 1000x563) without false-matching neighbours.
  const RATIO_TOLERANCE = 0.005

  const makePreset = (w, h) => ({ label: `${w}:${h}`, ratio: w / h })

  const horizontal = [makePreset(16, 9), makePreset(3, 2), makePreset(4, 3)]
  const square = [makePreset(1, 1)]
  const vertical = [makePreset(4, 5), makePreset(3, 4), makePreset(9, 16)]
  const allPresets = [...horizontal, ...square, ...vertical]

  const currentRatio = computed(() => (props.height ? props.width / props.height : 0))
  const isActive = preset => currentRatio.value > 0
    && Math.abs(preset.ratio - currentRatio.value) / preset.ratio < RATIO_TOLERANCE
  const active = computed(() => allPresets.find(isActive) || null)

  const open = ref(false)
  const root = ref(null)

  const choose = preset => {
    emit('select', preset.ratio)
    open.value = false
  }

  const onClickOutside = e => {
    if (root.value && !root.value.contains(e.target)) {
      open.value = false
    }
  }

  onMounted(() => document.addEventListener('click', onClickOutside))
  onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))
</script>
