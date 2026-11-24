<template>
  <div class="border-b border-black/5 px-3 py-3">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Groups</span>
      <button
        type="button"
        class="rounded-sm border border-slate-200 px-2 py-0.5 text-xs font-medium text-slate-600 hover:border-slate-300"
        @click="newGroup"
      >
        + New group
      </button>
    </div>

    <ul v-if="orderedGroups.length" role="tree" aria-label="Layer groups" class="mt-2 space-y-1">
      <li
        v-for="(group, index) in orderedGroups"
        :key="group.id"
        role="treeitem"
        :aria-expanded="(!group.collapsed).toString()"
        :aria-label="`Group ${group.name || 'Untitled'}`"
        class="rounded-sm border border-slate-100"
      >
        <div class="flex items-center gap-1 px-2 py-1">
          <button
            type="button"
            class="text-slate-400 hover:text-slate-600"
            :aria-label="group.collapsed ? 'Expand group' : 'Collapse group'"
            @click="update(group, { collapsed: !group.collapsed })"
          >
            <span class="inline-block w-3 text-xs">{{ group.collapsed ? '▸' : '▾' }}</span>
          </button>
          <input
            class="min-w-0 flex-1 rounded-sm border border-transparent bg-transparent px-1 text-xs text-slate-700 hover:border-slate-200 focus:border-indigo-400 focus:outline-hidden"
            :value="group.name"
            :aria-label="`Rename group ${group.name || 'Untitled'}`"
            @change="update(group, { name: $event.target.value })"
          >
          <button
            type="button"
            class="text-slate-400 hover:text-slate-600"
            :aria-pressed="(group.visible !== false).toString()"
            :aria-label="group.visible === false ? 'Show group' : 'Hide group'"
            @click="update(group, { visible: group.visible === false })"
          >
            <span class="text-xs">{{ group.visible === false ? '🚫' : '👁' }}</span>
          </button>
          <button type="button" class="text-slate-300 hover:text-slate-600 disabled:opacity-30" aria-label="Move group up" :disabled="index === 0" @click="move(group, -1)">↑</button>
          <button type="button" class="text-slate-300 hover:text-slate-600 disabled:opacity-30" aria-label="Move group down" :disabled="index === orderedGroups.length - 1" @click="move(group, 1)">↓</button>
          <button type="button" class="text-slate-300 hover:text-red-600" aria-label="Delete group" @click="remove(group)">✕</button>
        </div>
        <ul v-if="!group.collapsed" role="group" class="space-y-0.5 px-2 pb-2">
          <li
            v-for="layer in layersInGroup(group.id)"
            :key="layer.id"
            role="treeitem"
            class="flex items-center justify-between rounded-sm px-2 py-0.5 text-xs text-slate-600 hover:bg-slate-50"
          >
            <span class="truncate">{{ layer.title || layer.key }}</span>
            <button type="button" class="text-slate-300 hover:text-slate-600" :aria-label="`Remove ${layer.title || layer.key} from group`" @click="assign(layer.id, null)">remove</button>
          </li>
          <li v-if="!layersInGroup(group.id).length" class="px-2 py-0.5 text-xs italic text-slate-400">No layers</li>
        </ul>
      </li>
    </ul>

    <div v-if="ungroupedLayers.length" class="mt-2">
      <span class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Ungrouped</span>
      <ul class="mt-1 space-y-0.5">
        <li v-for="layer in ungroupedLayers" :key="layer.id" class="flex items-center justify-between gap-1 px-1 text-xs text-slate-600">
          <span class="truncate">{{ layer.title || layer.key }}</span>
          <select
            v-if="orderedGroups.length"
            class="rounded-sm border border-slate-200 bg-white px-1 py-0.5 text-[11px]"
            :aria-label="`Move ${layer.title || layer.key} to a group`"
            @change="assign(layer.id, $event.target.value || null)"
          >
            <option value="">Move to…</option>
            <option v-for="group in orderedGroups" :key="group.id" :value="group.id">{{ group.name || 'Untitled' }}</option>
          </select>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
  import { computed } from 'vue'
  import { useStore } from 'vuex'

  const store = useStore()

  const orderedGroups = computed(() => [...store.state.groups].sort((a, b) => (a.order ?? 0) - (b.order ?? 0)))
  const zIndexOf = layer => (layer.properties.find(p => p.name === 'zIndex')?.value) ?? 0
  const layersInGroup = groupId => store.state.layers.filter(layer => layer.groupId == groupId).sort((a, b) => zIndexOf(b) - zIndexOf(a))
  const ungroupedLayers = computed(() => store.state.layers.filter(layer => layer.groupId == null))

  const newGroup = () => {
    const id = `group-${Math.floor(Math.random() * 1000000)}`
    store.commit('addGroup', { id, name: `Group ${store.state.groups.length + 1}`, visible: true, collapsed: false, order: store.state.groups.length })
    store.commit('setDirty', true)
  }

  const update = (group, changes) => {
    store.commit('updateGroup', { id: group.id, changes })
    store.commit('setDirty', true)
  }

  const remove = group => {
    // Recoverable (layers keep their content, just lose groupId) but T-503
    // requires a guard so a stray click can't silently dissolve a group.
    if (! confirm(`Delete group "${group.name || 'Untitled'}"? Its layers stay on the canvas, ungrouped.`)) {
      return
    }
    store.commit('removeGroup', group.id)
    store.commit('setDirty', true)
  }

  const assign = (layerId, groupId) => {
    store.commit('setLayerGroup', { layerId, groupId })
    restampBands()
    store.commit('setDirty', true)
  }

  // Move a group up/down in z-order and re-stamp member zIndices so each group
  // occupies a contiguous band (the Lead's binding F7 ordering rule).
  const move = (group, delta) => {
    const ordered = orderedGroups.value
    const index = ordered.findIndex(g => g.id === group.id)
    const target = index + delta
    if (target < 0 || target >= ordered.length) {
      return
    }
    const a = ordered[index], b = ordered[target]
    const aOrder = a.order ?? index, bOrder = b.order ?? target
    store.commit('updateGroup', { id: a.id, changes: { order: bOrder } })
    store.commit('updateGroup', { id: b.id, changes: { order: aOrder } })
    restampBands()
    store.commit('setDirty', true)
  }

  // Sequence: grouped layers (by group order, then intra-group zIndex) first,
  // ungrouped last; re-stamp zIndex descending so groups stay contiguous.
  const restampBands = () => {
    const sequence = []
    orderedGroups.value.forEach(group => {
      layersInGroup(group.id).forEach(layer => sequence.push(layer.id))
    })
    ungroupedLayers.value
      .slice()
      .sort((a, b) => zIndexOf(b) - zIndexOf(a))
      .forEach(layer => sequence.push(layer.id))

    const total = store.state.layers.length
    const newLayers = store.state.layers.map(layer => {
      const position = sequence.indexOf(layer.id)
      const newZ = position < 0 ? zIndexOf(layer) : total - position
      return { ...layer, properties: layer.properties.map(p => p.name === 'zIndex' ? { ...p, value: newZ } : p) }
    })
    store.commit('setLayers', newLayers)
  }
</script>
