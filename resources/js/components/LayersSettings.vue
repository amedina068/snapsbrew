<template>
  <div>
    <LayerGroups />
    <ul class="py-1">
      <draggable v-model="orderedLayers" item-key="id" handle=".layer-drag-handle">
        <template #item="{element}">
          <LayoutLayerItem :layer="element"/>
        </template>
      </draggable>
    </ul>
  </div>
</template>

<script setup>
  import { orderBy } from 'lodash'
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  import draggable from 'vuedraggable'
  import LayoutLayerItem from './LayoutLayerItem.vue'
  import LayerGroups from './LayerGroups.vue'

  const store = useStore()
  const layers = computed(() => store.state.layers)
  const orderedLayers = computed({
    get() {
      return orderBy(layers.value.map(layer => {
        return {
          key: layer.key,
          visible: layer.visible,
          id: layer.id,
          zIndex: layer.properties.find(property => property.name == 'zIndex').value,
          locked: layer.locked
        }
      }), 'zIndex', 'desc')
    },
    set(value) {
      const newLayers = value.map((newLayer, index) => {
        const originalLayer = layers.value.find(layer => layer.id == newLayer.id)
        const properties = originalLayer.properties.map(property => {
          return property.name == 'zIndex' ? { ...property, value: layers.value.length - index } : property
        })
        return { ...originalLayer, properties}
      })
      store.commit('setLayers', newLayers)
    },
  })

</script>