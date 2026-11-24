<template>
  <Menu as="div" class="relative">
    <MenuButton>
      <slot name="trigger"></slot>
    </MenuButton>
    <MenuItems class="absolute right-0 z-20 mt-2 w-52 rounded-lg border border-gray-200 bg-white py-1 shadow-md focus:outline-hidden">
      <MenuItem v-for="item in items" :key="item.url" v-slot="{ active }">
        <a :href="item.url" :class="[active ? 'bg-rose-50' : '', 'block px-4 py-2 text-sm text-gray-700']">{{ item.text }}</a>
      </MenuItem>
      <!-- A MenuItem, so that the arrow keys reach it. Tab closes a Headless UI menu. -->
      <MenuItem v-if="logoutForm" v-slot="{ active }">
        <button type="submit" :form="logoutForm" :class="[active ? 'bg-rose-50' : '', 'block w-full px-4 py-2 text-left text-sm text-gray-700']">Log out</button>
      </MenuItem>
    </MenuItems>
  </Menu>
</template>

<script setup>
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'

defineProps({
  items: {
    type: Array,
    required: true,
  },
  // The id of the form that logs the user out. Without it, the menu has no Log out item.
  logoutForm: {
    type: String,
    default: null,
  },
})
</script>
