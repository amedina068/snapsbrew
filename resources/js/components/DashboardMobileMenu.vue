<template>
  <nav v-show="open" id="dashboard-menu" class="border-t border-gray-200 sm:hidden" aria-label="Mobile">
    <a v-for="link in [...links, ...profileItems.map(item => ({ href: item.url, label: item.text }))]"
       :key="link.href"
       :href="link.href"
       :class="[link.active ? 'bg-rose-50 text-rose-600' : 'text-gray-600 hover:bg-gray-50', 'block px-4 py-2 text-base']">
      {{ link.label }}
    </a>
    <button type="submit" form="logout-form" class="block w-full px-4 py-2 text-left text-base text-gray-600 hover:bg-gray-50">Log out</button>
  </nav>
</template>

<script setup>
import { onMounted, ref } from 'vue'

defineProps({
  links: { type: Array, required: true },
  profileItems: { type: Array, default: () => [] },
})

const open = ref(false)

// The menu button is Blade markup outside this component, so the state is written to it here.
onMounted(() => window.emitter.on('toggleDashboardMenu', () => {
  open.value = ! open.value
  document.querySelector('[aria-controls="dashboard-menu"]')?.setAttribute('aria-expanded', String(open.value))
}))
</script>
