<template>
  <div class="divide-y divide-black/5 text-sm">
    <div class="px-4 py-3">
      <PositionButtons />
    </div>
    <div :key="`${component}-${layer.id}`" v-for="component in sidebarComponents" class="px-4 py-3">
      <component :is="component"/>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'
  
  import PositionButtons from './PositionButtons.vue'
  import PropertySize from './PropertySize.vue'
  import PropertyInput from './PropertyInput.vue'
  import PropertyShadow from './PropertyShadow.vue'
  import PropertyBorder from './PropertyBorder.vue'
  import PropertyPosition from './PropertyPosition.vue'
  import PropertyBackground from './PropertyBackground.vue'
  import PropertyBackgroundType from './PropertyBackgroundType.vue'
  import PropertyImageBackground from './PropertyImageBackground.vue'
  import PropertyRotate from './PropertyRotate.vue'
  import PropertyOpacity from './PropertyOpacity.vue'
  import PropertySvgBackground from './PropertySvgBackground'
  import PropertyTextContent from './PropertyTextContent.vue'
  import PropertyBorderRadius from './PropertyBorderRadius.vue'
  import PropertyQrContent from './PropertyQrContent.vue'
  import PropertyBarcode from './PropertyBarcode.vue'
  import PropertyFlip from './PropertyFlip.vue'
  import PropertyBlendMode from './PropertyBlendMode.vue'
  import PropertyFilters from './PropertyFilters.vue'
  import PropertyAutoResize from './PropertyAutoResize.vue'

  const store = useStore()
  const layer = computed(() => store.state.selectedLayer)

  const components = {
    image: [
      PropertyImageBackground,
    ],
    text: [
      PropertyBackgroundType,
      PropertyBackground,
      PropertyTextContent,
      PropertyAutoResize,
    ],
    empty: [
      PropertyBackgroundType,
      PropertyBackground,
    ],
    svg: [
      PropertySvgBackground,
    ],
    qr_code: [
      PropertyQrContent,
    ],
    barcode: [
      PropertyBarcode,
    ],
    all: [
      PropertySize,
      PropertyPosition,
      PropertyRotate,
      PropertyFlip,
      PropertyOpacity,
      PropertyFilters,
      PropertyBlendMode,
      PropertyBorderRadius,
      PropertyShadow,
      PropertyBorder,
    ]
  }

  // Null-guard: a layer type with no entry (e.g. an externally-created type the
  // editor doesn't yet have a panel for) must not spread `undefined` and
  // white-screen the sidebar.
  const sidebarComponents = computed(() => [ ...(components[layer.value.type] ?? []), ...components.all ])
</script>