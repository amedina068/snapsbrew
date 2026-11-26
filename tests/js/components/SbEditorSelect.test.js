import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SbEditorSelect from '../../../resources/js/components/SbEditorSelect.vue'

// The font family select gives its options as { family } objects and sets
// optionKey and optionText to "family". The other selects use { value, name }.
const fonts = [{ family: 'ABeeZee' }, { family: 'Abel' }, { family: 'DM Serif Display' }]

describe('SbEditorSelect', () => {
  it('selects the option whose optionKey matches the value', () => {
    const wrapper = mount(SbEditorSelect, {
      props: { id: 'font_family', options: fonts, value: 'DM Serif Display', optionKey: 'family', optionText: 'family' },
    })

    expect(wrapper.find('select').element.value).toBe('DM Serif Display')
  })

  it('selects the option whose value matches with the default keys', () => {
    const wrapper = mount(SbEditorSelect, {
      props: { id: 'text_align', options: [{ value: 'left', name: 'Left' }, { value: 'center', name: 'Center' }], value: 'center' },
    })

    expect(wrapper.find('select').element.value).toBe('center')
  })
})
