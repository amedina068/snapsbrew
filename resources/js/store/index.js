import LayersImporter from '../LayersImporter'
import { createStore } from 'vuex'
import FontsApplier from '../libs/FontsApplier'
import SvgDesigns from '../libs/SvgDesigns'

const store = createStore({
  strict: import.meta.env.DEV,
  state() {
    return {
      undo: [],
      snapToGrid: true,
      redo: [],
      calculatedZoom: 1,
      templateId: null,
      templateUuid: null,
      userId: null,
      isOwner: false,
      isAuthenticated: false,
      layoutWidth: 600,
      layoutHeight: 380,
      layoutZoom: 100,
      layoutTitle: 'My template',
      // The `templates.size` preset value ('instagram_story', 'custom', …).
      // Several presets share one shape, so the stored value is the only exact
      // way to know which preset a template uses.
      layoutSize: null,
      layoutBackground: '#ffffff',
      layers: [],
      // Editor construct over flat layers: [{ id, name, visible, collapsed, order }].
      // Each layer carries a nullable `groupId`. Storage stays flat.
      groups: [],
      snap: null,
      selectedLayer: null,
      isDirty: false,
      // layerId -> false when a layer's content is invalid (e.g. a barcode whose
      // value doesn't match its symbology). Blocks Save/Render until fixed.
      layerValidity: {},
    }
  },
  mutations: {
    addRedoToUndo: (state, payload) => state.undo.push(payload),
    removeLastRedoStep: state => state.redo.pop(),
    updateSnapToGrid: (state, payload) => state.snapToGrid = payload,
    addUndoToRedo: (state, payload) => state.redo.push(payload),
    removeLastUndoStep: state => state.undo.pop(),
    setUndo: (state, payload) => {
      state.undo.push(payload)
      state.redo = []
    },
    updateZoom: (state, payload) => state.calculatedZoom = payload,
    storeUserId: (state, payload) => state.userId = payload,
    authenticateUser: state => state.isAuthenticated = true,
    setTemplate: (state, template) => {
      state.templateId = template.id
      state.templateUuid = template.uuid
      state.isOwner = template.is_owner
      state.layoutWidth = template.width
      state.layoutHeight = template.height
      state.layoutSize = template.size ?? null
      state.layers = (new LayersImporter(JSON.parse(template.layers))).import(),
        state.layoutTitle = template.title
      state.groups = template.groups ? JSON.parse(template.groups) : []
      state.isDirty = false
    },
    setDirty: (state, value) => state.isDirty = value,
    removeSnap: state => state.snap = null,
    setSnap: (state, snap) => state.snap = snap,
    setLayers: (state, layers) => state.layers = layers,
    addLayer: (state, newLayer) => state.layers.push(newLayer),
    selectLayer: (state, layerId) => state.selectedLayer = state.layers.find(layer => layer.id == layerId),
    removeLayerProperties: (state, payload) => {
      const layerIndex = state.layers.findIndex(layer => layer.id == payload.layerId)
      state.layers[layerIndex].properties = state.layers[layerIndex].properties.filter(property => !payload.properties.includes(property.name))
    },
    addLayerProperties: (state, payload) => {
      const layerIndex = state.layers.findIndex(layer => layer.id == payload.layerId)
      state.layers[layerIndex].properties = [...payload.properties, ...state.layers[layerIndex].properties]
    },
    updateLayer: (state, payload) => {
      const layerIndex = state.layers.findIndex(layer => layer.id == payload.layerId)
      state.layers[layerIndex][payload.name] = payload.value
    },
    updateLayerProperty: (state, payload) => {
      const layerIndex = state.layers.findIndex(layer => layer.id == payload.layerId)
      if (layerIndex < 0) {
        return
      }

      const propertyExists = state.layers[layerIndex].properties.find(property => property.name == payload.propertyName)

      if (!propertyExists) {
        state.layers[layerIndex].properties.push({
          name: payload.propertyName,
          value: payload.propertyValue
        })
      } else {
        const properties = state.layers[layerIndex].properties.map(property => {
          return property.name == payload.propertyName ? { ...property, value: payload.propertyValue } : property
        })
        state.layers[layerIndex].properties = properties
      }


    },
    updateLayoutProperty: (state, payload) => state[payload.property] = payload.value,
    setLayerValidity: (state, payload) => state.layerValidity = { ...state.layerValidity, [payload.layerId]: payload.valid },
    setGroups: (state, groups) => state.groups = groups,
    addGroup: (state, group) => state.groups.push(group),
    updateGroup: (state, payload) => {
      const group = state.groups.find(group => group.id == payload.id)
      if (group) {
        Object.assign(group, payload.changes)
      }
    },
    removeGroup: (state, groupId) => {
      state.groups = state.groups.filter(group => group.id != groupId)
      // Orphaned layers fall back to ungrouped rather than referencing a
      // deleted group (which would render as ungrouped anyway, but keep state clean).
      state.layers.forEach(layer => {
        if (layer.groupId == groupId) {
          layer.groupId = null
        }
      })
    },
    setLayerGroup: (state, payload) => {
      const layer = state.layers.find(layer => layer.id == payload.layerId)
      if (layer) {
        layer.groupId = payload.groupId
      }
    },
  },
  getters: {
    // True when any CURRENT layer reports invalid content. Stale entries for
    // deleted layers are ignored because we only inspect existing layer ids.
    hasInvalidLayers: state => state.layers.some(layer => state.layerValidity[layer.id] === false),
    // Set of group ids whose visible flag is false.
    hiddenGroupIds: state => new Set(state.groups.filter(group => group.visible === false).map(group => group.id)),
    // A layer is hidden on the canvas when its own visible flag is false OR it
    // belongs to a hidden group — mirrors the render path's group skip.
    isLayerHidden: (state, getters) => layer =>
      layer.visible === false || (layer.groupId != null && getters.hiddenGroupIds.has(layer.groupId)),
    backgroundReadableStyles: (state, getters) => (layerId) => {
      const layer = state.layers.find(layer => layer.id == layerId)
      if (!layer) {
        return {}
      }
      const backgroundTypeProperty = layer.properties.find(property => property.name == 'backgroundType')

      if (!backgroundTypeProperty) {
        return {}
      }

      const backgroundType = backgroundTypeProperty.value
      if (backgroundType == 'linear') {
        const backgroundGradientColor = layer.properties.find(property => property.name == 'backgroundGradientColor')?.value
        if (!backgroundGradientColor) {
          return {}
        }
        const colors = (backgroundGradientColor.steps ?? []).map(item => {
          let string = item.color
          if ((item.percentage !== null && item.percentage !== '') && !isNaN(item.percentage)) {
            string += ` ${item.percentage}%`
          }
          return string
        }).join(',')
        return {
          background: `linear-gradient(${backgroundGradientColor.degrees}deg, ${colors})`
        }
      }

      if (backgroundType == 'radial') {
        const backgroundGradientColor = layer.properties.find(property => property.name == 'backgroundGradientColor')?.value
        if (!backgroundGradientColor) {
          return {}
        }
        return {
          background: `radial-gradient(${backgroundGradientColor.from}, ${backgroundGradientColor.to})`
        }
      }

      if (backgroundType == 'color') {
        const backgroundColor = layer.properties.find(property => property.name == 'backgroundColor')?.value
        return {
          background: backgroundColor ?? 'transparent'
        }
      }

      if (backgroundType == 'svg') {
        const backgroundSvg = layer.properties.find(property => property.name == 'backgroundSvg')?.value
        if (!backgroundSvg) {
          return {}
        }
        return {
          background: `url("${(SvgDesigns[backgroundSvg.design]).replace('{svgColor}', encodeURIComponent(backgroundSvg.color))}")`
        }
      }

      const backgroundImageProperty = layer.properties.find(property => property.name == 'backgroundImage')
      const backgroundImage = backgroundImageProperty?.value ?? '/images/placeholder.png'
      const backgroundSize = layer.properties.find(property => property.name == 'backgroundSize')?.value || 'cover'
      return {
        'background-size': backgroundSize,
        'background-image': backgroundImage ? `url(${backgroundImage})` : 'none'
      }
    },
    calculatedWidth: state => state.calculatedZoom * state.layoutWidth,
    calculatedHeight: state => state.calculatedZoom * state.layoutHeight,
    property: state => (layerId, propertyName) => {
      const layer = state.layers.find(layer => layer.id == layerId)
      if (!layer) {
        return {}
      }
      const property = layer.properties.find(property => property.name == propertyName)
      return property ? property : {}
    },
    readableStyles: state => (layerId, propertyName) => {
      const layer = state.layers.find(layer => layer.id == layerId)
      let propertyValue = layer.properties.find(property => property.name == propertyName).value
      if (propertyName == 'backgroundType') {
        return ({
          'linear': 'background',
          'color': 'background-color',
          'radial': 'background',
          'image': 'background-image'
        })[propertyValue]
      }
      if (propertyName != 'backgroundColor' && propertyName != 'zIndex' && propertyName != 'backgroundType') {
        return `${propertyValue}px`
      }

      if (propertyName != 'backgroundColor') {
        return propertyValue
      }

      const backgroundType = layer.properties.find(property => property.name == 'backgroundType').value

      if (backgroundType == 'linear') {
        return `linear-gradient(${propertyValue.degrees}deg, ${propertyValue.from}, ${propertyValue.to})`
      }

      if (backgroundType == 'radial') {
        return `radial-gradient(${propertyValue.from}, ${propertyValue.to})`
      }

      if (backgroundType == 'image') {
        return `url("${propertyValue}")`
      }

      if (backgroundType == 'svg') {
        const svgDesign = (SvgDesigns[propertyValue.design]).replace('{svgColor}', encodeURIComponent(propertyValue.color))
        return `url("${svgDesign}")`
      }

      return propertyValue
    }
  },
  actions: {
    undo({ state, commit }) {
      if (!state.undo.length) {
        return
      }
      const undoActions = state.undo[state.undo.length - 1]
      undoActions.undoActions.forEach(({ action, payload }) => commit(action, payload))
      commit('addUndoToRedo', undoActions)
      commit('removeLastUndoStep')
    },
    redo({ state, commit }) {
      if (!state.redo.length) {
        return
      }
      const redoActions = state.redo[state.redo.length - 1]
      redoActions.redoActions.forEach(({ action, payload }) => commit(action, payload))
      commit('addRedoToUndo', redoActions)
      commit('removeLastRedoStep')
    },
    updateGlobalFonts({ state }) {
      const fontFamilies = state.layers.map(layer => layer.properties.find(property => property.name == 'fontFamily'))
        .filter(fontFamily => !!fontFamily)
        // fontFamily is a plain string; tolerate a legacy { family } object.
        .map(fontFamily => {
          const value = fontFamily.value
          const family = typeof value === 'object' && value !== null ? (value.family ?? '') : (value ?? '')
          return String(family).replace(/ /g, '+')
        })
        .filter(family => family.length > 0);

      (new FontsApplier(fontFamilies))[fontFamilies.length ? 'buildLink' : 'destroyLink']()
    }
  }
})

// Mutations that represent a real content change to the template. When any of
// them fire, mark the editor dirty so the unsaved-changes guard can warn before
// the user navigates away. setTemplate (initial load) and save reset the flag.
const DIRTYING_MUTATIONS = [
  'setLayers',
  'addLayer',
  'removeLayerProperties',
  'addLayerProperties',
  'updateLayer',
  'updateLayerProperty',
  'updateLayoutProperty',
  'setUndo',
]

store.subscribe(mutation => {
  if (DIRTYING_MUTATIONS.includes(mutation.type) && !store.state.isDirty) {
    store.commit('setDirty', true)
  }
})

export default store
