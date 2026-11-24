const REQUIRED_PROPERTIES = {
  default: [
    {name: 'backgroundType', value: 'color'},
    {name: 'zIndex', value: 99},
    {name: 'rotate', value: 0},
    {name: 'opacity', value: 1},
    {name: 'aspectRatio', value: false},
    {name: 'borderRadiusLeftTop', value: 0},
    {name: 'borderRadiusRightTop', value: 0},
    {name: 'borderRadiusLeftBottom', value: 0},
    {name: 'borderRadiusRightBottom', value: 0},
    {name: 'borderRadiusLocked', value: false},
    {name: 'borderStyle', value: 'none'},
    {name: 'borderWidth', value: 1},
    {name: 'borderColor', value: '#000000'},
    {name: 'height', value: 50},
    {name: 'width', value: 50},
    {name: 'positionX', value: 0},
    {name: 'positionY', value: 0},
    {name: 'flipHorizontal', value: false},
    {name: 'flipVertical', value: false},
    {name: 'mixBlendMode', value: 'normal'},
    {name: 'filterBlur', value: 0},
    {name: 'filterBrightness', value: 1},
    {name: 'filterContrast', value: 1},
    {name: 'filterSaturate', value: 1},
    {name: 'filterGrayscale', value: 0},
    {name: 'autoResize', value: false},
  ],
  qr_code: [
    {name: 'zIndex', value: 99},
    {name: 'rotate', value: 0},
    {name: 'opacity', value: 1},
    {name: 'height', value: 120},
    {name: 'width', value: 120},
    {name: 'positionX', value: 0},
    {name: 'positionY', value: 0},
    {name: 'qrContentType', value: 'url'},
    {name: 'qrContent', value: 'https://example.com'},
  ],
  barcode: [
    {name: 'zIndex', value: 99},
    {name: 'rotate', value: 0},
    {name: 'opacity', value: 1},
    {name: 'height', value: 100},
    {name: 'width', value: 300},
    {name: 'positionX', value: 0},
    {name: 'positionY', value: 0},
    {name: 'barcodeFormat', value: 'Code128'},
    {name: 'barcodeContent', value: '12345678'},
  ]
}

export default class LayersImporter {
  constructor(layers) {
    this.layers = Array.isArray(layers) ? layers : []
  }

  // Normalizes every layer into the full shape the editor relies on. Layers
  // created outside the editor (MCP, API) only carry { key, properties }, but
  // the editor filters on `visible`, keys/selects on `id`, and renders panels by
  // `type` — without those a layer is invisible and unselectable. Backfilling
  // here fixes both existing and future externally-created templates on load.
  import() {
    return this.layers.map((layer, index) => this.normalizeLayer(layer, index))
  }

  normalizeLayer(layer, index) {
    const rawProperties = Array.isArray(layer.properties) ? [...layer.properties] : []
    // fontFamily is canonically a plain string; collapse a legacy/MCP { family }
    // object shape so every reader (picker, render, @font-face) agrees.
    const properties = rawProperties.map(property => {
      if (property.name === 'fontFamily' && property.value && typeof property.value === 'object') {
        return { ...property, value: property.value.family ?? '' }
      }
      return property
    })
    const type = layer.type ?? this.inferType(properties)

    // The editor paints a layer's background from `backgroundType`. External
    // layers omit it, and the generic backfill below would force 'color' — which
    // hides image/svg layers behind their solid backgroundColor (the color
    // getter short-circuits before the image branch). Seed the right one first
    // so the filter skips the default.
    if (type !== 'qr_code' && type !== 'barcode' && properties.findIndex(property => property.name == 'backgroundType') < 0) {
      properties.push({name: 'backgroundType', value: this.backgroundTypeFor(type)})
    }

    const backgroundType = properties.find(property => property.name == 'backgroundType')
    if (backgroundType && backgroundType.value == 'color'
      && properties.findIndex(property => property.name == 'backgroundColor') < 0) {
      properties.push({name: 'backgroundColor', value: 'rgba(255,255,255,0)'})
    }

    const requiredProperties = this.requiredPropertiesFor(type)
      .filter(required => properties.findIndex(property => property.name == required.name) < 0)

    return {
      id: layer.id ?? `id-${layer.key ?? 'layer'}-${index}`,
      key: layer.key ?? `layer_${index + 1}`,
      title: layer.title ?? layer.key ?? `Layer ${index + 1}`,
      type,
      visible: layer.visible ?? true,
      locked: layer.locked ?? false,
      // Nullable group membership (F7). Absent ⇒ ungrouped (back-compat).
      groupId: layer.groupId ?? null,
      properties: [...properties, ...requiredProperties],
    }
  }

  // Mirrors the editor's layer types (AddLayerActions.vue) from the properties an
  // externally-created layer carries, so panels render correctly.
  inferType(properties) {
    const has = name => properties.some(property => property.name == name)

    if (has('qrContent') || has('qrContentType')) return 'qr_code'
    if (has('barcodeContent') || has('barcodeFormat')) return 'barcode'
    if (has('textContent')) return 'text'
    if (has('backgroundImage')) return 'image'
    if (has('backgroundSvg')) return 'svg'
    return 'empty'
  }

  // The editor's backgroundType vocabulary (PropertyBackgroundType.vue):
  // image/svg drive their own background branches; everything else is a color.
  backgroundTypeFor(type) {
    if (type === 'image') return 'image'
    if (type === 'svg') return 'svg'
    return 'color'
  }

  requiredPropertiesFor(type) {
    return REQUIRED_PROPERTIES[type] ?? REQUIRED_PROPERTIES.default
  }
}
