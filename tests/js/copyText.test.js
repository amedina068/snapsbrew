import { describe, it, expect, vi, beforeEach } from 'vitest'
import { copyText, listenForCopyButtons } from '../../resources/js/copyText'

describe('copyText', () => {
  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('uses the Clipboard API in a secure context', async () => {
    const writeText = vi.fn().mockResolvedValue()
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    vi.stubGlobal('isSecureContext', true)

    await copyText('https://snapsbrew.example/register?invitation=abc')

    expect(writeText).toHaveBeenCalledWith('https://snapsbrew.example/register?invitation=abc')
  })

  it('falls back to execCommand on plain HTTP', async () => {
    vi.stubGlobal('isSecureContext', false)
    document.execCommand = vi.fn().mockReturnValue(true)

    await copyText('abc')

    expect(document.execCommand).toHaveBeenCalledWith('copy')
    expect(document.querySelector('textarea')).toBeNull()
  })

  it('copies the text of a data-copy-text button and shows Copied', async () => {
    vi.stubGlobal('isSecureContext', false)
    document.execCommand = vi.fn().mockReturnValue(true)
    document.body.innerHTML = '<button data-copy-text="https://x.test/i"><span data-copy-label>Copy invite link</span></button>'
    listenForCopyButtons(document.body)

    document.querySelector('span').click()
    await Promise.resolve()

    expect(document.execCommand).toHaveBeenCalledWith('copy')
    expect(document.querySelector('[data-copy-label]').textContent).toBe('Copied')
  })
})
