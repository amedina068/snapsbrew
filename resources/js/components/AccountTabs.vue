<template>
  <div class="mt-8" v-cloak>
    <slot name="alerts"></slot>

    <slot name="header"></slot>

    <div class="mt-6 overflow-x-auto">
      <div role="tablist" aria-label="Account sections" class="inline-flex gap-1 rounded-full border border-gray-200 bg-white p-1" @keydown="onKeydown">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          :id="`account-tab-${tab.key}`"
          :ref="el => (tabRefs[tab.key] = el)"
          type="button"
          role="tab"
          :aria-selected="isActive(tab.key).toString()"
          :aria-controls="`account-panel-${tab.key}`"
          :tabindex="isActive(tab.key) ? 0 : -1"
          class="focus-ring rounded-full px-4 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors"
          :class="isActive(tab.key) ? 'bg-rose-600 text-white' : 'text-gray-600 hover:bg-slate-100 hover:text-gray-900'"
          @click="setTab(tab.key)"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div class="mt-8">
      <section
        v-for="tab in tabs"
        :key="tab.key"
        v-show="isActive(tab.key)"
        :id="`account-panel-${tab.key}`"
        role="tabpanel"
        :aria-labelledby="`account-tab-${tab.key}`"
      >
        <slot :name="tab.key"></slot>
      </section>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    initialTab: {
      type: String,
      default: 'company'
    }
  },
  data() {
    return {
      activeTab: this.initialTab,
      tabRefs: {},
      tabs: [
        { key: 'company', label: 'Company Information' },
        { key: 'users', label: 'Users' },
        { key: 'developers', label: 'Developers' },
        { key: 'security', label: 'Security' },
      ],
    }
  },
  mounted() {
    this.updateQuery(this.activeTab)
  },
  methods: {
    setTab(tab) {
      this.activeTab = tab
      this.updateQuery(tab)
    },
    isActive(tab) {
      return this.activeTab === tab
    },
    // The arrow keys, Home and End move between the tabs, as the ARIA tabs pattern asks.
    onKeydown(event) {
      const keys = this.tabs.map(tab => tab.key)
      const index = keys.indexOf(this.activeTab)
      const next = {
        ArrowRight: (index + 1) % keys.length,
        ArrowLeft: (index - 1 + keys.length) % keys.length,
        Home: 0,
        End: keys.length - 1,
      }[event.key]

      if (next === undefined) {
        return
      }

      event.preventDefault()
      this.setTab(keys[next])
      this.$nextTick(() => this.tabRefs[keys[next]]?.focus())
    },
    updateQuery(tab) {
      if (typeof window === 'undefined' || typeof URL === 'undefined') {
        return
      }

      const url = new URL(window.location.href)
      url.searchParams.set('tab', tab)
      window.history.replaceState({}, '', url)
    }
  }
}
</script>
