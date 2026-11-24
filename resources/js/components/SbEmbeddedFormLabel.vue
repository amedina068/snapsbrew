<template>
    <label :for="for" class="block text-sm font-semibold text-gray-800">
        <slot />
    </label>
</template>

<script setup>
    const props = defineProps({
        for: {
            type: String,
            required: true
        }
    })
</script>