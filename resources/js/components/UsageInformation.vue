<template>
  <div>
    <div>
      <h4 class="text-xl font-bold tracking-wide">Usage</h4>
    </div>
    <div class="mt-4">
      <div class="text-right font-medium text-sm">{{ usageData.creation_used }} / {{ usageData.creation_available }}</div>
      <div class="bg-gray-200 mt-1 rounded-full overflow-hidden">
        <div class="h-3 bg-indigo-600 rounded-full" :style="{width: `${usageData.creation_used / usageData.creation_available * 100}%`}"></div>
      </div>
    </div>
  </div>
</template>

<script>
  import { toRefs } from 'vue'
  export default {
    props: {
      usageData: {
        type: Object,
        required: true,
      }
    },

    setup(props) {
      const { usageData } = toRefs(props)

      return {
        usageData
      }
    }
  }
</script>