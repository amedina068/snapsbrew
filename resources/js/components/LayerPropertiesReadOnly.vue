<template>
  <div class="mt-4">
    <ul class="space-y-4 text-sm">
      <component
        v-for="item in sidebarComponents"
        :is="item.component"
        :key="`${item.key}-${layer.id}`"
        v-bind="item.props"
      />
    </ul>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  
  import PropertySize from './PropertySize.vue'
  import PropertyPosition from './PropertyPosition.vue'
  import PropertyBorder from './PropertyBorder.vue' 
  import PropertyShadow from './PropertyShadow.vue' 
  import PropertyBackground from './PropertyBackground.vue'
  import PropertyBackgroundType from './PropertyBackgroundType.vue'
  import PropertyImageBackgroundReadOnly from './PropertyImageBackgroundReadOnly'
  import PropertyRotate from './PropertyRotate.vue'
  import PropertyOpacity from './PropertyOpacity.vue'
  import PropertySvgBackgroundReadOnly from './PropertySvgBackgroundReadOnly'
  import PropertyTextContentReadOnly from './PropertyTextContentReadOnly'
  import PropertyBorderRadius from './PropertyBorderRadius.vue'
  import PropertyQrContent from './PropertyQrContent.vue'

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)

  const components = {
    image: [
      PropertyImageBackgroundReadOnly,
    ],
    text: [
      PropertyBackgroundType,
      PropertyBackground,
      PropertyTextContentReadOnly,
    ],
    empty: [
      PropertyBackgroundType,
      PropertyBackground,
    ],
    svg: [
      PropertySvgBackgroundReadOnly,
    ],
    qr_code: [
      PropertyQrContent,
    ],
    all: [
      PropertySize,
      PropertyPosition,
      PropertyRotate,
      PropertyOpacity,
      PropertyBorderRadius,
      PropertyBorder,
      PropertyShadow,
    ]
  }

  const sidebarComponents = computed(() => {
    const rawComponents = [ ...components[layer.value.type], ...components.all ]

    const disabledComponents = new Set([
      PropertySize,
      PropertyPosition,
      PropertyRotate,
      PropertyOpacity,
      PropertyBorderRadius,
      PropertyBorder,
      PropertyShadow,
      PropertyBackground,
      PropertyBackgroundType,
      PropertyQrContent,
    ])

    return rawComponents.map((component, index) => ({
      component,
      key: component.name || component.__name || index,
      props: disabledComponents.has(component)
        ? { disabled: true }
        : {},
    }))
  })
</script>
