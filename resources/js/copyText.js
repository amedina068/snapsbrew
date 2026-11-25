/**
 * Copies text to the clipboard. The Clipboard API needs HTTPS or localhost.
 * A self-hosted instance on plain HTTP uses the older execCommand path.
 */
export function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text)
  }

  const input = document.body.appendChild(document.createElement('textarea'))
  input.value = text
  input.setAttribute('readonly', '')
  input.style.position = 'absolute'
  input.style.opacity = '0'
  input.select()
  document.execCommand('copy')
  input.remove()

  return Promise.resolve()
}

/**
 * A button with `data-copy-text` copies that text. It works inside v-pre
 * blocks, where Vue components do not run. A child with `data-copy-label`
 * shows "Copied" for two seconds.
 */
export function listenForCopyButtons(root = document) {
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy-text]')
    if (!button) return

    copyText(button.dataset.copyText).then(() => {
      const label = button.querySelector('[data-copy-label]')
      if (!label) return
      const original = label.textContent
      label.textContent = 'Copied'
      window.setTimeout(() => { label.textContent = original }, 2000)
    })
  })
}
