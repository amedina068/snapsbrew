{{-- A read-only value, for example a URL, with a button that copies it. --}}
@props(['value'])
<div {{ $attributes->merge(['class' => 'relative flex items-stretch rounded-lg border border-gray-200 bg-slate-50']) }}>
  <code v-pre class="min-w-0 flex-1 px-3 py-2.5 font-mono text-xs leading-5 break-all text-gray-700">{{ $value }}</code>
  <copy-text-button link="{{ $value }}" class="flex">
    <template v-slot:default="{ copyLink }">
      <button type="button" @click="copyLink" class="focus-ring flex h-full items-center gap-1.5 rounded-r-lg border-l border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 hover:bg-slate-100" aria-label="Copy to clipboard">
        <x-icon name="clipboard-document" class="size-4" />
        <span class="hidden sm:inline">Copy</span>
      </button>
    </template>
  </copy-text-button>
</div>
