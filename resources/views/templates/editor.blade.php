<x-app-layout>
  <div class="flex flex-col h-screen overflow-hidden bg-gray-50">
    <div class="relative z-30">
      <sc-editor-topbar>
          <template v-slot:actions>
            <template-versions></template-versions>
            <template-actions></template-actions>
          </template>
      </sc-editor-topbar>
    </div>
    <div class="relative flex w-full flex-1 flex-wrap overflow-hidden items-stretch">
      <div class="z-20 flex bg-gray-50 max-h-full overflow-scroll">
        <layout-sidebar></layout-sidebar>
      </div>
      <div class="mx-auto flex flex-1 items-center" id="editor-container">
        <sc-editor :template="{{$template}}" />
      </div>
      <div class="z-20 flex max-h-full overflow-scroll bg-white border-l border-gray-200 w-64 max-w-sm">
        <layer-sidebar></layer-sidebar>
      </div>
    </div>
  </div>
  <background-image-media-library></background-image-media-library>
  <template-settings-modal :sizes="{{ json_encode($templateSizes) }}"></template-settings-modal>
</x-app-layout>
