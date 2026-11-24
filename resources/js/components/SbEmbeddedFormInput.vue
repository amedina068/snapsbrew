<template>
    <input
        class="focus-ring block min-h-10 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-500 focus:ring-0"
        :type="type"
        :placeholder="placeholder"
        :id="id"
        :value="value"
    />
</template>

<script setup>
    const props = defineProps({
        type: {
            type: String,
            required: true,
        },
        placeholder: {
            type: String,
            required: false,
        },
        id: {
            type: String,
            required: true,
        },
        value: {
            type: String,
            required: false,
        }
    })

</script>