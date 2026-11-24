<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div class="space-y-3">
      <x-ui.breadcrumb :items="$breadcrumbs" />
      <h1 class="text-lg tracking-wide text-gray-900">Snap</h1>
    </div>
  </x-slot>

  <div class="flex flex-col gap-6 lg:flex-row">
    <x-ui.panel class="min-w-0 flex-1">
      @if ($snapshot->isComplete() && $snapshot->imageUrl)
        <h2 class="text-sm font-semibold text-gray-700">Image link</h2>
        <x-ui.copy-field class="mt-2" :value="$snapshot->imageUrl" />
        <img class="mt-6 w-full rounded-lg border border-gray-200" src="{{ $snapshot->imageUrl }}" alt="Snap image">
      @elseif ($snapshot->status === \App\Models\Snap::STATUS_FAILED)
        <x-ui.empty-state bare icon="photo" title="This snap did not render">
          Generate the snap again from its template. The status and the request log are on the right.
        </x-ui.empty-state>
      @else
        <x-ui.empty-state bare icon="photo" title="The image is not ready yet">
          Reload this page in a few seconds to see the image.
        </x-ui.empty-state>
      @endif
    </x-ui.panel>

    <x-ui.panel v-pre class="w-full self-start lg:w-80">
      <dl class="space-y-5 text-sm">
        <div>
          <dt class="text-xs font-semibold text-gray-500">Created</dt>
          <dd class="mt-1 text-gray-900">{{ optional($snapshot->created_at)->format('M d, Y H:i') ?? '—' }}</dd>
        </div>

        <div>
          <dt class="text-xs font-semibold text-gray-500">Status</dt>
          <dd class="mt-1"><x-ui.status-badge :status="$snapshot->status" /></dd>
        </div>

        <div>
          <dt class="text-xs font-semibold text-gray-500">Template</dt>
          <dd class="mt-1">
            @if ($template && $templateLink)
              <a href="{{ $templateLink }}" class="focus-ring rounded-sm text-rose-700 underline underline-offset-2">{{ $template->title }}</a>
            @else
              <span class="text-gray-500">Template unavailable</span>
            @endif
          </dd>
        </div>

        <div>
          <dt class="text-xs font-semibold text-gray-500">Import</dt>
          <dd class="mt-1">
            @if ($import && $importLink)
              <a href="{{ $importLink }}" class="focus-ring rounded-sm text-rose-700 underline underline-offset-2">{{ $import->title ?? 'View import' }}</a>
            @else
              <span class="text-gray-500">Not part of an import</span>
            @endif
          </dd>
        </div>

        <div>
          <dt class="text-xs font-semibold text-gray-500">Form</dt>
          <dd class="mt-1">
            @if ($form && $formLink)
              <a href="{{ $formLink }}" class="focus-ring rounded-sm text-rose-700 underline underline-offset-2">{{ $form->title }}</a>
            @else
              <span class="text-gray-500">No form linked</span>
            @endif
          </dd>
        </div>

        <div>
          <dt class="text-xs font-semibold text-gray-500">API log</dt>
          <dd class="mt-1">
            @if ($apiLogLink)
              <a href="{{ $apiLogLink }}" class="focus-ring rounded-sm text-rose-700 underline underline-offset-2">View request log</a>
            @else
              <span class="text-gray-500">Log not available</span>
            @endif
          </dd>
        </div>
      </dl>
    </x-ui.panel>
  </div>
</x-dashboard-layout>
