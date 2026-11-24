<template>
  <div>
    <div>
      <sb-editor-label for="text">Text</sb-editor-label>
      <div class="mt-1">
        <sb-editor-textarea
          id="text"
          :value="textContent"
          @change="updateLayerProperty('textContent', $event)"
        />
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="font_size">Font size</sb-editor-label>
      <div class="mt-1">
        <sb-editor-input
          id="font_size"
          :value="fontSize"
          @change="updateLayerProperty('fontSize', $event)"
          type="number"
        />
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="line_height">Line height</sb-editor-label>
      <div class="mt-1">
        <sb-editor-input
          id="line_height"
          :value="lineHeight"
          @change="updateLayerProperty('lineHeight', $event)"
          type="number"
        />
      </div>
    </div>
    <div class="pt-4 grid grid-cols-3 gap-2">
      <div><sb-editor-label for="font_weight">Weight</sb-editor-label><sb-editor-input id="font_weight" type="number" min="100" max="900" step="100" :value="fontWeight" @change="updateLayerProperty('fontWeight', $event)" /></div>
      <div><sb-editor-label for="font_style">Style</sb-editor-label><sb-editor-select id="font_style" :value="fontStyle" @change="updateLayerProperty('fontStyle', $event)" :options="styleOptions" /></div>
      <div><sb-editor-label for="letter_spacing">Tracking</sb-editor-label><sb-editor-input id="letter_spacing" type="number" min="-20" max="100" :value="letterSpacing" @change="updateLayerProperty('letterSpacing', $event)" /></div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="font_family">Font family</sb-editor-label>
      <div class="mt-1">
        <sb-editor-select
          :value="fontFamily"
          @change="updateLayerProperty('fontFamily', $event)"
          id="font_family"
          :options="googleFonts"
          optionKey="family"
          optionText="family"
        />
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="text_align">Text align</sb-editor-label>
      <div class="mt-1">
        <sb-editor-select
          id="text_align"
          :value="textAlignment"
          @change="updateLayerProperty('textAlignment', $event)"
          :options="alignmentOptions"
        />  
      </div>
    </div>
    <div class="pt-4">
      <sb-editor-label for="text_color">Text color</sb-editor-label>
      <div class="mt-1">
        <ScColorPicker :value="fontColor" @change="updateLayerProperty('fontColor', $event)"/>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { computed, ref, watch } from 'vue'
  import { useStore } from 'vuex'
  import ScColorPicker from './ScColorPicker'

  const store = useStore()
  const googleFonts = ref([])

  const alignmentOptions = [
    { value: 'left', name: 'Left' },
    { value: 'center', name: 'Center' },
    { value: 'right', name: 'Right' },
  ]
  const styleOptions = [{ value: 'normal', name: 'Normal' }, { value: 'italic', name: 'Italic' }]
  
  // Merge company custom fonts in front of the Google catalog and inject an
  // @font-face for each so the editor preview renders in the real face.
  const loadCustomFonts = () => fetch('/fonts/list')
    .then(response => response.ok ? response.json() : { data: [] })
    .then(({ data }) => {
      const customFonts = (data || []).filter(font => font.family && font.url)
      if (! customFonts.length) {
        return []
      }
      let styleEl = document.querySelector('style#custom-font-faces')
      if (! styleEl) {
        styleEl = document.createElement('style')
        styleEl.id = 'custom-font-faces'
        document.head.appendChild(styleEl)
      }
      styleEl.textContent = customFonts
        .map(font => `@font-face{font-family:'${font.family}';src:url('${font.url}');}`)
        .join('')
      return customFonts.map(font => ({ family: font.family }))
    })
    .catch(() => [])

  Promise.all([
    fetch('/json/google-fonts.json').then(response => response.json()).then(({ items }) => items).catch(() => []),
    loadCustomFonts(),
  ]).then(([googleItems, customItems]) => {
    googleFonts.value = [...customItems, ...googleItems]
  })

  const layer = computed(() => store.state.selectedLayer)
  const textContent = computed(() => store.getters.property(layer.value.id, 'textContent').value)
  // fontFamily is stored as a plain string. Tolerate a legacy/MCP { family }
  // object shape by collapsing it to the string here.
  const fontFamily = computed(() => {
    if (! layer.value) {
      return ''
    }
    const value = store.getters.property(layer.value.id, 'fontFamily').value
    return typeof value === 'object' && value !== null ? (value.family ?? '') : (value ?? '')
  })
  const textAlignment = computed(() => store.getters.property(layer.value.id, 'textAlignment').value)
  const fontSize = computed(() => store.getters.property(layer.value.id, 'fontSize').value)
  const lineHeight = computed(() => store.getters.property(layer.value.id, 'lineHeight').value ?? (1.2 * fontSize.value))
  const fontColor = computed(() => store.getters.property(layer.value.id, 'fontColor').value)
  const fontWeight = computed(() => store.getters.property(layer.value.id, 'fontWeight').value ?? 400)
  const fontStyle = computed(() => store.getters.property(layer.value.id, 'fontStyle').value ?? 'normal')
  const letterSpacing = computed(() => store.getters.property(layer.value.id, 'letterSpacing').value ?? 0)
  const setUndo = payload => store.commit('setUndo', payload)
  const updateLayerProperty = (property, newValue) => {
    const properties = ({
      'textContent': textContent,
      'fontFamily': fontFamily,
      'textAlignment': textAlignment,
      'lineHeight': lineHeight,
      'fontSize': fontSize,
      'fontColor': fontColor,
    })

    const undoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: property,
        propertyValue: properties[property].value
      } }
    ]
    const redoActions = [
      { action: 'updateLayerProperty', payload: {
        layerId: layer.value.id,
        propertyName: property,
        propertyValue: newValue
      } }
    ]

    store.commit('updateLayerProperty', {
      layerId: layer.value.id,
      propertyName: property,
      propertyValue: newValue
    })

    setUndo({ undoActions, redoActions })
  }

  watch(fontFamily, () => store.dispatch('updateGlobalFonts'))
</script>
