<template>
  <div class="flex justify-between items-baseline">
    <span class="text-sm text-gray-500">{{ name }}</span>
    <input class="text-right w-16 text-sm rounded-xs shadow-sm px-2 h-6" :value="value" @input="updateLayerProperty($event)" >
  </div>
</template>

<script>
  import { toRefs } from 'vue'
  import { useStore } from 'vuex'
  export default {
    props: {
      layerId: {
        type: String,
        required: true,
      },
      name: {
        type: String,
        required: true,
      },
      value: {
        type: [ Number, String ],
        required: true,
      }
    },
    setup(props) {
      const store = useStore()
      const { layerId, name } = toRefs(props)
      const updateLayerProperty = event => store.commit('updateLayerProperty', {
        layerId: layerId.value,
        propertyName: name.value,
        propertyValue: event.target.value
      })

      return {
        updateLayerProperty
      }
    }
  }
</script>