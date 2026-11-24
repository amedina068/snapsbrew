import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import store from '../../../resources/js/store'
import PropertyBarcode from '../../../resources/js/components/PropertyBarcode.vue'
import SbEditorLabel from '../../../resources/js/components/SbEditorLabel.vue'
import SbEditorSelect from '../../../resources/js/components/SbEditorSelect.vue'

const initialState = JSON.parse(JSON.stringify(store.state))

const selectBarcodeLayer = (format, content) => {
  store.commit('setLayers', [{
    id: 'barcode-1',
    type: 'barcode',
    visible: true,
    properties: [
      { name: 'barcodeFormat', value: format },
      { name: 'barcodeContent', value: content },
    ],
  }])
  store.commit('selectLayer', 'barcode-1')
}

// app.js registers the SbEditor* components globally, so the test does too.
const mountPanel = (props = {}) => mount(PropertyBarcode, {
  props,
  global: {
    plugins: [store],
    components: { SbEditorLabel, SbEditorSelect },
  },
})

const contentOf = () => store.getters.property('barcode-1', 'barcodeContent').value

beforeEach(() => {
  store.replaceState(JSON.parse(JSON.stringify(initialState)))
})

describe('PropertyBarcode', () => {
  it('shows the symbology error and blocks Save when the content does not match EAN-13', () => {
    selectBarcodeLayer('EAN-13', '12345')

    const wrapper = mountPanel()

    const input = wrapper.get('#barcode_content')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#barcode_content_error').text()).toBe('EAN-13 needs 12 or 13 digits.')
    expect(store.state.layerValidity['barcode-1']).toBe(false)
    expect(store.getters.hasInvalidLayers).toBe(true)
  })

  it('writes the typed content to the store, records an undo step, and clears the error', async () => {
    selectBarcodeLayer('EAN-13', '12345')
    const wrapper = mountPanel()

    await wrapper.get('#barcode_content').setValue('590123412345')

    expect(contentOf()).toBe('590123412345')
    expect(store.state.undo.at(-1)).toEqual({
      undoActions: [{ action: 'updateLayerProperty', payload: { layerId: 'barcode-1', propertyName: 'barcodeContent', propertyValue: '12345' } }],
      redoActions: [{ action: 'updateLayerProperty', payload: { layerId: 'barcode-1', propertyName: 'barcodeContent', propertyValue: '590123412345' } }],
    })
    expect(wrapper.find('#barcode_content_error').exists()).toBe(false)
    expect(store.getters.hasInvalidLayers).toBe(false)
  })

  it('checks the content again when the symbology changes', async () => {
    selectBarcodeLayer('EAN-13', '5901234123457')
    const wrapper = mountPanel()
    expect(store.state.layerValidity['barcode-1']).toBe(true)

    // 13 digits are valid for EAN-13 but not for UPC-A.
    await wrapper.get('#barcode_format').setValue('UPC-A')

    expect(store.getters.property('barcode-1', 'barcodeFormat').value).toBe('UPC-A')
    expect(wrapper.get('#barcode_content_error').text()).toBe('UPC-A needs exactly 12 digits.')
    expect(store.state.layerValidity['barcode-1']).toBe(false)
  })

  it('shows read-only values and changes nothing when it is disabled', () => {
    selectBarcodeLayer('Code128', 'SKU-42')

    const wrapper = mountPanel({ disabled: true })

    expect(wrapper.find('#barcode_content').exists()).toBe(false)
    expect(wrapper.find('#barcode_format').exists()).toBe(false)
    expect(wrapper.text()).toContain('SKU-42')
    expect(store.state.undo).toEqual([])
  })
})
