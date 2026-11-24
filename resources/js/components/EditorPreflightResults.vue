<template>
  <section aria-live="polite" aria-labelledby="preflight-results-title">
    <h2 id="preflight-results-title" class="sr-only">Preflight results</h2>
    <p v-if="!issues.length" class="text-sm text-emerald-700">Ready to render.</p>
    <ul v-else class="space-y-2 text-sm text-rose-700">
      <li v-for="issue in issues" :key="`${issue.code}:${issue.path}`">
        <strong>{{ issue.code }}</strong>: {{ issue.message }}
        <button
          v-if="issue.path"
          type="button"
          class="ml-2 underline"
          @click="$emit('focus-issue', issue.path)"
        >
          Fix issue
        </button>
      </li>
    </ul>
  </section>
</template>

<script setup>
defineProps({ issues: { type: Array, default: () => [] } })
defineEmits(['focus-issue'])
</script>
