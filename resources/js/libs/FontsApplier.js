export default class FontsApplier {
  constructor(fonts) {
    this.fonts = fonts
  }

  buildLink() {
    let link = document.querySelector('link#font-families')
    if (! link) {
      link = this.createLink()
    }
    link.href = this.buildSrc()
  }

  destroyLink() {
    let link = document.querySelector('link#font-families')
    if (! link) {
      return
    }
    link.remove()
  }

  createLink() {
    let link = document.createElement('link')
    link.type = 'text/css'
    link.rel = 'stylesheet'
    link.id = 'font-families'
    document.head.append(link)
    return link
  }

  buildSrc() {
    const fonts = this.fonts.map(fontFamily => `family=${fontFamily}`).join('&')
    return `https://fonts.googleapis.com/css2?${fonts}`
  }
}