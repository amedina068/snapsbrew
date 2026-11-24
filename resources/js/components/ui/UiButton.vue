<template>
  <!-- The Vue twin of the Blade x-ui.button: a pill with the same variants and sizes. -->
  <component
    :is="href ? 'a' : 'button'"
    :href="href || undefined"
    :type="href ? undefined : type"
    :class="[base, variants[variant] ?? variants.primary, sizes[size] ?? sizes.md]"
  >
    <slot />
  </component>
</template>

<script setup>
defineProps({
  href: { type: String, default: null },
  type: { type: String, default: 'button' },
  variant: { type: String, default: 'primary' },
  size: { type: String, default: 'md' },
})

const base = 'focus-ring inline-flex shrink-0 items-center justify-center rounded-full border font-semibold whitespace-nowrap transition-colors disabled:cursor-not-allowed disabled:opacity-50'

const variants = {
  primary: 'border-rose-700 bg-rose-600 text-white hover:bg-rose-700',
  secondary: 'border-gray-300 bg-white text-gray-800 hover:border-gray-400 hover:bg-slate-50',
  danger: 'border-red-200 bg-white text-red-700 hover:border-red-300 hover:bg-red-50',
  ghost: 'border-transparent bg-transparent text-gray-600 hover:bg-slate-100 hover:text-gray-900',
  'danger-ghost': 'border-transparent bg-transparent text-red-700 hover:bg-red-50 hover:text-red-800',
}

const sizes = {
  sm: 'min-h-8 gap-1.5 px-3 text-xs',
  md: 'min-h-10 gap-2 px-5 text-sm',
}
</script>
