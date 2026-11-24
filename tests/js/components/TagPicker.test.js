import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TagPicker from '../../../resources/js/components/TagPicker.vue'

// TagPicker calls the global axios instance that bootstrap.js puts on window.
let http

beforeEach(() => {
  vi.useFakeTimers()
  http = { get: vi.fn(), post: vi.fn() }
  vi.stubGlobal('axios', http)
})

afterEach(() => {
  vi.useRealTimers()
})

const mountPicker = (modelValue = []) => mount(TagPicker, { props: { modelValue } })

const type = async (wrapper, text) => {
  await wrapper.get('#tag-search').setValue(text)
}

describe('TagPicker', () => {
  it('waits 250 ms after the last key, sends one search, and hides the tags that are already selected', async () => {
    http.get.mockResolvedValue({ data: { data: [{ id: 1, name: 'promo' }, { id: 2, name: 'promo-summer' }] } })
    const wrapper = mountPicker([{ id: 1, name: 'promo' }])

    await type(wrapper, 'pro')
    await type(wrapper, 'promo')
    vi.advanceTimersByTime(249)
    expect(http.get).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    await flushPromises()

    expect(http.get).toHaveBeenCalledTimes(1)
    expect(http.get).toHaveBeenCalledWith('/api/v1/tags', { params: { search: 'promo' } })
    const suggestions = wrapper.findAll('.max-h-56 button').map(button => button.text())
    expect(suggestions).toEqual(['promo-summer'])
  })

  it('creates a new tag on Enter and emits the new selection', async () => {
    http.post.mockResolvedValue({ data: { data: { id: 9, name: 'Launch' } } })
    const wrapper = mountPicker([{ id: 1, name: 'promo' }])

    await type(wrapper, '  Launch ')
    await wrapper.get('#tag-search').trigger('keydown.enter')
    await flushPromises()

    expect(http.post).toHaveBeenCalledWith('/api/v1/tags', { name: 'Launch' })
    expect(wrapper.emitted('update:modelValue')).toEqual([[[{ id: 1, name: 'promo' }, { id: 9, name: 'Launch' }]]])
    expect(wrapper.get('#tag-search').element.value).toBe('')
  })

  it('does not offer to create a tag whose name is already selected, in any letter case', async () => {
    const wrapper = mountPicker([{ id: 1, name: 'Promo' }])

    await type(wrapper, 'promo')

    expect(wrapper.text()).not.toContain('Create')
    await wrapper.get('#tag-search').trigger('keydown.enter')
    expect(http.post).not.toHaveBeenCalled()
  })

  it('shows the message of the server when the create request fails', async () => {
    http.post.mockRejectedValue({ response: { data: { message: 'The name has already been taken.' } } })
    const wrapper = mountPicker()

    await type(wrapper, 'dupe')
    await wrapper.get('#tag-search').trigger('keydown.enter')
    await flushPromises()

    expect(wrapper.text()).toContain('The name has already been taken.')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('removes a selected tag and emits the remaining tags', async () => {
    const wrapper = mountPicker([{ id: 1, name: 'promo' }, { id: 2, name: 'summer' }])

    await wrapper.findAll('button[aria-label="Remove tag"]')[0].trigger('click')

    expect(wrapper.emitted('update:modelValue')).toEqual([[[{ id: 2, name: 'summer' }]]])
  })
})
