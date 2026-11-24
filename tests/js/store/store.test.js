import { beforeEach, describe, expect, it } from 'vitest'
import store from '../../../resources/js/store'

// The app uses one store instance. Each test starts again from a copy of the
// state that the store had when the module loaded.
const initialState = JSON.parse(JSON.stringify(store.state))

const template = (layers, extra = {}) => ({
  id: 7,
  uuid: 'tpl-uuid',
  is_owner: true,
  width: 1080,
  height: 1080,
  size: 'instagram_post',
  title: 'Launch',
  layers: JSON.stringify(layers),
  groups: null,
  ...extra,
})

const propertyValue = (layerId, name) => store.getters.property(layerId, name).value

beforeEach(() => {
  store.replaceState(JSON.parse(JSON.stringify(initialState)))
  document.head.innerHTML = ''
})

describe('store mutations', () => {
  it('setTemplate normalizes the layers and leaves the editor clean', () => {
    store.commit('setTemplate', template([{ key: 'headline', properties: [{ name: 'textContent', value: 'Hi' }] }]))

    expect(store.state.templateId).toBe(7)
    expect(store.state.layoutSize).toBe('instagram_post')
    expect(store.state.layers).toHaveLength(1)
    expect(store.state.layers[0]).toMatchObject({ id: 'id-headline-0', type: 'text', visible: true })
    expect(store.state.isDirty).toBe(false)
  })

  it('a content mutation marks the editor dirty', () => {
    store.commit('setTemplate', template([{ key: 'headline', properties: [{ name: 'textContent', value: 'Hi' }] }]))

    store.commit('updateLayerProperty', { layerId: 'id-headline-0', propertyName: 'textContent', propertyValue: 'Hello' })

    expect(store.state.isDirty).toBe(true)
  })

  it('updateLayerProperty replaces a property, adds a missing one, and ignores an unknown layer', () => {
    store.commit('setTemplate', template([{ key: 'headline', properties: [{ name: 'textContent', value: 'Hi' }] }]))
    const before = store.state.layers[0].properties.length

    store.commit('updateLayerProperty', { layerId: 'id-headline-0', propertyName: 'textContent', propertyValue: 'Hello' })
    store.commit('updateLayerProperty', { layerId: 'id-headline-0', propertyName: 'letterSpacing', propertyValue: 2 })
    store.commit('updateLayerProperty', { layerId: 'missing', propertyName: 'textContent', propertyValue: 'x' })

    expect(propertyValue('id-headline-0', 'textContent')).toBe('Hello')
    expect(propertyValue('id-headline-0', 'letterSpacing')).toBe(2)
    expect(store.state.layers[0].properties).toHaveLength(before + 1)
  })

  it('removeGroup moves the layers of that group back to ungrouped', () => {
    store.commit('setLayers', [
      { id: 'a', groupId: 'g1', visible: true, properties: [] },
      { id: 'b', groupId: 'g2', visible: true, properties: [] },
    ])
    store.commit('setGroups', [{ id: 'g1', visible: true }, { id: 'g2', visible: true }])

    store.commit('removeGroup', 'g1')

    expect(store.state.groups.map(group => group.id)).toEqual(['g2'])
    expect(store.state.layers.map(layer => layer.groupId)).toEqual([null, 'g2'])
  })
})

describe('store getters', () => {
  it('isLayerHidden is true for a hidden layer and for a layer in a hidden group', () => {
    const visibleLayer = { id: 'a', groupId: 'g1', visible: true, properties: [] }
    const hiddenLayer = { id: 'b', groupId: null, visible: false, properties: [] }
    store.commit('setLayers', [visibleLayer, hiddenLayer])
    store.commit('setGroups', [{ id: 'g1', visible: true }])

    expect(store.getters.isLayerHidden(visibleLayer)).toBe(false)
    expect(store.getters.isLayerHidden(hiddenLayer)).toBe(true)

    store.commit('updateGroup', { id: 'g1', changes: { visible: false } })

    expect(store.getters.isLayerHidden(visibleLayer)).toBe(true)
  })

  it('hasInvalidLayers ignores a validity entry for a deleted layer', () => {
    store.commit('setLayers', [{ id: 'a', properties: [] }])
    store.commit('setLayerValidity', { layerId: 'deleted', valid: false })

    expect(store.getters.hasInvalidLayers).toBe(false)

    store.commit('setLayerValidity', { layerId: 'a', valid: false })

    expect(store.getters.hasInvalidLayers).toBe(true)
  })

  it('backgroundReadableStyles builds a linear gradient from the steps', () => {
    store.commit('setLayers', [{
      id: 'a',
      properties: [
        { name: 'backgroundType', value: 'linear' },
        {
          name: 'backgroundGradientColor',
          value: { degrees: 90, steps: [{ color: '#fff', percentage: 0 }, { color: '#000', percentage: '' }] },
        },
      ],
    }])

    expect(store.getters.backgroundReadableStyles('a')).toEqual({
      background: 'linear-gradient(90deg, #fff 0%,#000)',
    })
  })
})

describe('store actions', () => {
  it('undo and redo replay the recorded mutations in order', async () => {
    store.commit('setTemplate', template([{ key: 'headline', properties: [{ name: 'textContent', value: 'Hi' }] }]))
    const step = value => ({ action: 'updateLayerProperty', payload: { layerId: 'id-headline-0', propertyName: 'textContent', propertyValue: value } })

    store.commit('updateLayerProperty', step('Hello').payload)
    store.commit('setUndo', { undoActions: [step('Hi')], redoActions: [step('Hello')] })

    await store.dispatch('undo')

    expect(propertyValue('id-headline-0', 'textContent')).toBe('Hi')
    expect(store.state.undo).toHaveLength(0)
    expect(store.state.redo).toHaveLength(1)

    await store.dispatch('redo')

    expect(propertyValue('id-headline-0', 'textContent')).toBe('Hello')
    expect(store.state.undo).toHaveLength(1)
    expect(store.state.redo).toHaveLength(0)
  })

  it('undo with an empty history changes nothing', async () => {
    await store.dispatch('undo')

    expect(store.state.undo).toEqual([])
    expect(store.state.redo).toEqual([])
  })

  it('updateGlobalFonts adds one Google Fonts link for the fonts of the layers and removes it when no font is left', async () => {
    store.commit('setLayers', [
      { id: 'a', properties: [{ name: 'fontFamily', value: 'Open Sans' }] },
      { id: 'b', properties: [{ name: 'fontFamily', value: { family: 'Roboto Slab' } }] },
      { id: 'c', properties: [] },
    ])

    await store.dispatch('updateGlobalFonts')

    const links = document.querySelectorAll('link#font-families')
    expect(links).toHaveLength(1)
    expect(links[0].href).toBe('https://fonts.googleapis.com/css2?family=Open+Sans&family=Roboto+Slab')

    store.commit('setLayers', [{ id: 'c', properties: [] }])
    await store.dispatch('updateGlobalFonts')

    expect(document.querySelector('link#font-families')).toBeNull()
  })
})
