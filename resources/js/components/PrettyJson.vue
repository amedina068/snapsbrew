<template>
  <div class="bg-gray-100 rounded-md px-4 py-2 text-gray-800">
      <vue-json-pretty :data="json" />
  </div>
</template>

<script setup>
  import VueJsonPretty from 'vue-json-pretty';
  import { computed } from 'vue'
  import 'vue-json-pretty/lib/styles.css';

  const props = defineProps({
    json: {
      type: Object,
      required: true,
    }
  })

  const json = computed(() => props.json)
</script>