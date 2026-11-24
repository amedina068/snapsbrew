import { describe, expect, it } from 'vitest'
import LayersImporter from '../../resources/js/LayersImporter'

const valueOf = (layer, name) => layer.properties.find(property => property.name === name)?.value
const countOf = (layer, name) => layer.properties.filter(property => property.name === name).length

describe('LayersImporter', () => {
  it('gives an external { key, properties } layer the full editor shape', () => {
    const [layer] = new LayersImporter([
      { key: 'hero', properties: [{ name: 'backgroundImage', value: 'https://example.com/a.png' }] },
    ]).import()

    expect(layer).toMatchObject({
      id: 'id-hero-0',
      key: 'hero',
      title: 'hero',
      type: 'image',
      visible: true,
      locked: false,
      groupId: null,
    })
    // An image layer must not get the default 'color' background. That
    // default hides the image behind a solid backgroundColor.
    expect(valueOf(layer, 'backgroundType')).toBe('image')
    expect(countOf(layer, 'backgroundColor')).toBe(0)
    expect(valueOf(layer, 'width')).toBe(50)
  })

  it('infers the type of each layer from the properties that it carries', () => {
    const types = new LayersImporter([
      { key: 'a', properties: [{ name: 'textContent', value: 'Hi' }] },
      { key: 'b', properties: [{ name: 'backgroundSvg', value: { design: 'x' } }] },
      { key: 'c', properties: [{ name: 'qrContent', value: 'https://example.com' }] },
      { key: 'd', properties: [{ name: 'barcodeFormat', value: 'EAN-13' }] },
      { key: 'e', properties: [] },
    ]).import().map(layer => layer.type)

    expect(types).toEqual(['text', 'svg', 'qr_code', 'barcode', 'empty'])
  })

  it('collapses a legacy { family } font object to a plain string', () => {
    const [layer] = new LayersImporter([
      { key: 'title', properties: [{ name: 'textContent', value: 'Hi' }, { name: 'fontFamily', value: { family: 'Open Sans' } }] },
    ]).import()

    expect(valueOf(layer, 'fontFamily')).toBe('Open Sans')
  })

  it('keeps the values that a layer already has and does not add duplicates', () => {
    const [layer] = new LayersImporter([
      {
        id: 'custom-id',
        key: 'box',
        title: 'Box',
        visible: false,
        properties: [
          { name: 'backgroundType', value: 'color' },
          { name: 'backgroundColor', value: '#ff0000' },
          { name: 'width', value: 320 },
        ],
      },
    ]).import()

    expect(layer.id).toBe('custom-id')
    expect(layer.visible).toBe(false)
    expect(valueOf(layer, 'backgroundColor')).toBe('#ff0000')
    expect(valueOf(layer, 'width')).toBe(320)
    expect(countOf(layer, 'width')).toBe(1)
    expect(countOf(layer, 'backgroundType')).toBe(1)
  })

  it('uses the QR defaults and adds no background to a QR layer', () => {
    const [layer] = new LayersImporter([
      { key: 'qr', properties: [{ name: 'qrContent', value: 'https://snapsbrew.test' }] },
    ]).import()

    expect(countOf(layer, 'backgroundType')).toBe(0)
    expect(valueOf(layer, 'width')).toBe(120)
    expect(valueOf(layer, 'qrContentType')).toBe('url')
    expect(valueOf(layer, 'qrContent')).toBe('https://snapsbrew.test')
  })

  it('returns no layers when the input is not a list', () => {
    expect(new LayersImporter(null).import()).toEqual([])
    expect(new LayersImporter({ key: 'x' }).import()).toEqual([])
  })
})
