@php
  use Illuminate\Support\Str;
@endphp

<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    Import details
    <span class="block text-sm font-medium text-gray-500">Template: {{ $template->title }}</span>
    <span class="block text-sm font-medium text-gray-500">Import: {{ $import->title ?? 'Untitled import' }}</span>
  </x-slot>

  <x-slot name="pageActions">
    <div class="flex flex-wrap gap-2">
      <x-ui.button variant="secondary" href="{{ url('templates/'.$template->uuid.'/imports') }}">All imports</x-ui.button>
      @if (in_array($import->status, [\App\Models\SnapImport::STATUS_PENDING, \App\Models\SnapImport::STATUS_PROCESSING]))
        @php
          $payload = [
            'import' => $progress,
            'status_url' => url('imports/'.$import->uuid.'/status'),
            'cancel_url' => url('imports/'.$import->uuid.'/cancel'),
            'redirect_url' => url('templates/'.$template->uuid.'/imports/'.$import->uuid),
          ];
          $monitor = 'window.emitter.emit("snap-import:monitor", '.json_encode($payload).');';
        @endphp
        <x-ui.button type="button" :onclick="$monitor">Track progress</x-ui.button>
      @endif
    </div>
  </x-slot>

  <div v-pre class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <x-ui.panel title="Summary">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
          @foreach ([
            'Title' => $import->title ?? 'Untitled import',
            'Started' => $import->created_at->format('M d, Y H:i'),
            'Rows' => $progress['total_rows'],
            'Processed' => $progress['processed_rows'],
            'Batch ID' => $import->batch_id ?? '—',
            'Completed at' => optional($import->completed_at)->format('M d, Y H:i') ?? '—',
          ] as $label => $value)
            <div>
              <dt class="text-xs font-semibold text-gray-500">{{ $label }}</dt>
              <dd class="mt-1 break-words text-gray-900">{{ $value }}</dd>
            </div>
          @endforeach
          <div>
            <dt class="text-xs font-semibold text-gray-500">Status</dt>
            <dd class="mt-1"><x-ui.status-badge :status="$import->status" /></dd>
          </div>
          <div>
            <dt class="text-xs font-semibold text-gray-500">Webhook URL</dt>
            <dd class="mt-1 break-all text-gray-900">
              @if ($import->webhook_url)
                <a href="{{ $import->webhook_url }}" class="text-rose-700 underline underline-offset-2" target="_blank" rel="noopener">{{ Str::limit($import->webhook_url, 50) }}</a>
              @else
                —
              @endif
            </dd>
          </div>
        </dl>
      </x-ui.panel>

      <section aria-labelledby="import-snaps-heading">
        <h2 id="import-snaps-heading" class="text-lg font-semibold text-gray-900">Snaps</h2>
        <p class="mt-1 mb-4 text-sm text-gray-600">Completed: {{ $progress['completed'] }}, Pending: {{ $progress['pending'] }}, Failed: {{ $progress['failed'] }}, Canceled: {{ $progress['canceled'] }}</p>
        <x-ui.table caption="Snaps of this import">
          <x-slot name="head">
            <x-ui.th>Created</x-ui.th>
            <x-ui.th>Identifier</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th class="hidden sm:table-cell">Error</x-ui.th>
            <x-ui.th align="right">Link</x-ui.th>
          </x-slot>
          @forelse ($import->snaps as $snap)
            <tr>
              <x-ui.td class="whitespace-nowrap">{{ $snap->created_at->format('M d, Y H:i') }}</x-ui.td>
              <x-ui.td strong>{{ $snap->identifier ?? '—' }}</x-ui.td>
              <x-ui.td><x-ui.status-badge :status="$snap->status" /></x-ui.td>
              <x-ui.td class="hidden text-xs text-red-700 sm:table-cell">{{ Str::limit($snap->last_error, 60) }}</x-ui.td>
              <x-ui.td align="right">
                @if ($snap->status === \App\Models\Snap::STATUS_COMPLETED && $snap->imageUrl)
                  <a href="{{ $snap->imageUrl }}" target="_blank" class="focus-ring rounded-sm text-xs font-semibold text-rose-700 hover:text-rose-800">Open</a>
                @else
                  <span class="text-gray-400">—</span>
                @endif
              </x-ui.td>
            </tr>
          @empty
            <tr><x-ui.td colspan="5" class="py-8 text-center">No snaps created yet for this import.</x-ui.td></tr>
          @endforelse
        </x-ui.table>
      </section>
    </div>

    <x-ui.panel title="Need to adjust the file?" class="self-start">
      <p class="text-sm text-gray-600">Download the template again, tweak your rows, and upload a new spreadsheet from the imports page.</p>
      <div class="mt-4 flex flex-col gap-2">
        <x-ui.button variant="secondary" icon="arrow-down-tray" href="{{ url('templates/'.$template->uuid.'/imports/export') }}">Download import template</x-ui.button>
        <x-ui.button variant="secondary" icon="plus" href="{{ url('templates/'.$template->uuid.'/imports/create') }}">Start new import</x-ui.button>
      </div>
    </x-ui.panel>
  </div>

  <snap-import-progress-modal></snap-import-progress-modal>
</x-dashboard-layout>
