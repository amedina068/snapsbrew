<template>
  <Menu as="div" class="relative">
    <MenuButton class="focus-ring inline-flex h-10 items-center gap-2 rounded-full border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-800 hover:bg-slate-50">
      Add layer
      <ChevronDownIcon class="h-4 w-4 text-gray-500" aria-hidden="true" />
    </MenuButton>
    <MenuItems class="absolute right-0 z-10 mt-2 w-72 origin-top-right rounded-xl border border-gray-200 bg-white p-1.5 focus:outline-hidden">
      <MenuItem v-for="option in layerOptions" :key="option.value" v-slot="{ active }">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left"
          :class="active ? 'bg-indigo-50' : ''"
          @click="handleLayerSelection(option.value)"
        >
          <span class="grid size-8 shrink-0 place-items-center rounded-md" :class="active ? 'bg-white text-indigo-600' : 'bg-slate-100 text-slate-700'">
            <component :is="option.icon" class="h-4 w-4" aria-hidden="true" />
          </span>
          <span class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900">{{ option.title }}</span>
            <span class="block text-xs text-gray-500">{{ option.description }}</span>
          </span>
        </button>
      </MenuItem>
    </MenuItems>
  </Menu>
</template>

<script setup>
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue'
import { ChevronDownIcon, DocumentTextIcon, PhotoIcon, Squares2X2Icon, QrCodeIcon, Bars2Icon } from '@heroicons/vue/20/solid'
import { BeakerIcon } from '@heroicons/vue/24/outline'
import { computed } from 'vue'
import { useStore } from 'vuex'

defineOptions({ name: 'AddLayerActions' })

const store = useStore()
const layers = computed(() => store.state.layers)

const layerOptions = [
  {
    value: 'empty',
    title: 'Empty layer',
    description: 'Start with a blank layer configured for solid colors.',
    icon: Squares2X2Icon
  },
  {
    value: 'image',
    title: 'Image layer',
    description: 'Place an image placeholder with default sizing.',
    icon: PhotoIcon
  },
  {
    value: 'text',
    title: 'Text layer',
    description: 'Add a text block with starter typography settings.',
    icon: DocumentTextIcon
  },
  {
    value: 'qr_code',
    title: 'QR code layer',
    description: 'Reserve space for QR data with placeholder styling.',
    icon: QrCodeIcon
  },
  {
    value: 'barcode',
    title: 'Barcode layer',
    description: 'Add a Code128, EAN-13 or UPC-A barcode.',
    icon: Bars2Icon
  },
  {
    value: 'svg',
    title: 'SVG layer',
    description: 'Use a patterned SVG background preset.',
    icon: BeakerIcon
  }
]

const templateProperties = (template, currentLayers) => ({
  svg: [
    { name: 'backgroundType', value: 'svg' },
    { name: 'backgroundSvg', value: { design: 'diagonalStripes', color: '#64748b' } },
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'aspectRatio', value: false },
    { name: 'borderRadiusLeftTop', value: 0 },
    { name: 'borderRadiusRightTop', value: 0 },
    { name: 'borderRadiusLeftBottom', value: 0 },
    { name: 'borderRadiusRightBottom', value: 0 },
    { name: 'borderRadiusLocked', value: true },
    { name: 'borderStyle', value: 'none' },
    { name: 'borderWidth', value: 1 },
    { name: 'borderColor', value: '#000000' },
    { name: 'height', value: 50 },
    { name: 'width', value: 50 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 }
  ],
  text: [
    { name: 'textContent', value: 'lorem ipsum dolor' },
    { name: 'autoResize', value: false },
    { name: 'fontSize', value: 50 },
    { name: 'lineHeight', value: 60 },
    { name: 'fontFamily', value: 'Nunito' },
    { name: 'fontWeight', value: 400 },
    { name: 'fontStyle', value: 'normal' },
    { name: 'letterSpacing', value: 0 },
    { name: 'fontColor', value: '#000000' },
    { name: 'textAlignment', value: 'left' },
    { name: 'backgroundType', value: 'color' },
    { name: 'backgroundColor', value: 'rgba(255,255,255,0)' },
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'aspectRatio', value: false },
    { name: 'borderRadiusLeftTop', value: 0 },
    { name: 'borderRadiusRightTop', value: 0 },
    { name: 'borderRadiusLeftBottom', value: 0 },
    { name: 'borderRadiusRightBottom', value: 0 },
    { name: 'borderRadiusLocked', value: true },
    { name: 'borderStyle', value: 'none' },
    { name: 'borderWidth', value: 1 },
    { name: 'borderColor', value: '#000000' },
    { name: 'height', value: 50 },
    { name: 'width', value: 400 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 }
  ],
  empty: [
    { name: 'backgroundType', value: 'color' },
    { name: 'backgroundColor', value: '#2463eb' },
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'aspectRatio', value: false },
    { name: 'borderRadiusLeftTop', value: 0 },
    { name: 'borderRadiusRightTop', value: 0 },
    { name: 'borderRadiusLeftBottom', value: 0 },
    { name: 'borderRadiusRightBottom', value: 0 },
    { name: 'borderRadiusLocked', value: true },
    { name: 'borderStyle', value: 'none' },
    { name: 'borderWidth', value: 1 },
    { name: 'borderColor', value: '#000000' },
    { name: 'height', value: 100 },
    { name: 'width', value: 100 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 }
  ],
  image: [
    { name: 'backgroundType', value: 'image' },
    { name: 'backgroundSize', value: 'cover' },
    { name: 'objectSize', value: 'cover' },
    { name: 'backgroundImage', value: null },
    { name: 'imageFit', value: 'cover' },
    { name: 'imagePositionX', value: 50 },
    { name: 'imagePositionY', value: 50 },
    { name: 'imageMask', value: 'none' },
    { name: 'imageFocalX', value: 50 },
    { name: 'imageFocalY', value: 50 },
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'aspectRatio', value: 1 },
    { name: 'borderRadiusLeftTop', value: 0 },
    { name: 'borderRadiusRightTop', value: 0 },
    { name: 'borderRadiusLeftBottom', value: 0 },
    { name: 'borderRadiusRightBottom', value: 0 },
    { name: 'borderRadiusLocked', value: true },
    { name: 'borderStyle', value: 'none' },
    { name: 'borderWidth', value: 1 },
    { name: 'borderColor', value: '#000000' },
    { name: 'height', value: 100 },
    { name: 'width', value: 100 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 }
  ],
  qr_code: [
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'height', value: 160 },
    { name: 'width', value: 160 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 },
    { name: 'aspectRatio', value: 1 },
    { name: 'qrContentType', value: 'url' },
    { name: 'qrContent', value: 'https://example.com' }
  ],
  barcode: [
    { name: 'zIndex', value: currentLayers.length + 1 },
    { name: 'rotate', value: 0 },
    { name: 'opacity', value: 1 },
    { name: 'height', value: 100 },
    { name: 'width', value: 300 },
    { name: 'positionX', value: 0 },
    { name: 'positionY', value: 0 },
    { name: 'barcodeFormat', value: 'Code128' },
    { name: 'barcodeContent', value: '12345678' }
  ]
})[template]

const handleLayerSelection = (layerType) => {
  const currentLayers = layers.value
  const newLayer = {
    title: `Layer ${currentLayers.length + 1}`,
    key: `layer_${currentLayers.length + 1}`,
    visible: true,
    type: layerType,
    id: `id-${parseInt(Math.random() * 1000)}`,
    properties: templateProperties(layerType, currentLayers),
    locked: false,
    groupId: null
  }
  store.commit('addLayer', newLayer)
  store.commit('selectLayer', newLayer.id)
}
</script>
