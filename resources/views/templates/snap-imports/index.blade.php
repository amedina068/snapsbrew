<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates/')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-lg tracking-wide text-gray-900">Import snaps</h1>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <x-ui.button icon="plus" href="{{ url('templates/'.$template->uuid.'/imports/create') }}">New import</x-ui.button>
  </x-slot>

  <section v-pre class="mt-8" aria-labelledby="imports-heading">
    <h2 id="imports-heading" class="mb-4 text-lg font-semibold text-gray-900">Recent imports</h2>

    @if ($imports->count())
      <x-ui.table caption="Recent imports">
        <x-slot name="head">
          <x-ui.th>Title</x-ui.th>
          <x-ui.th>Started</x-ui.th>
          <x-ui.th>Status</x-ui.th>
          <x-ui.th>Progress</x-ui.th>
          <x-ui.th class="hidden sm:table-cell">Completed</x-ui.th>
          <x-ui.th class="hidden sm:table-cell">Failed</x-ui.th>
          <x-ui.th align="right">Actions</x-ui.th>
        </x-slot>
        @foreach ($imports as $import)
          @php
            $processed = $import->completed_snaps_count + $import->failed_snaps_count + $import->canceled_snaps_count;
            $importPayload = [
              'import' => [
                'uuid' => $import->uuid,
                'status' => $import->status,
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows ?? $processed,
                'completed' => $import->completed_snaps_count,
                'pending' => $import->pending_snaps_count,
                'failed' => $import->failed_snaps_count,
                'canceled' => $import->canceled_snaps_count,
              ],
              'status_url' => url('imports/'.$import->uuid.'/status'),
              'cancel_url' => url('imports/'.$import->uuid.'/cancel'),
              'redirect_url' => url('templates/'.$template->uuid.'/imports/'.$import->uuid),
            ];
            $monitor = 'window.emitter.emit("snap-import:monitor", '.json_encode($importPayload).');';
          @endphp
          <tr>
            <x-ui.td strong>
              {{ $import->title ?? 'Untitled import' }}
              <span class="block text-xs font-normal text-gray-500">Batch #{{ $import->id }}</span>
            </x-ui.td>
            <x-ui.td class="whitespace-nowrap">{{ $import->created_at->format('M d, Y H:i') }}</x-ui.td>
            <x-ui.td><x-ui.status-badge :status="$import->status" /></x-ui.td>
            <x-ui.td class="whitespace-nowrap">{{ $processed }} / {{ $import->total_rows }}</x-ui.td>
            <x-ui.td class="hidden sm:table-cell">{{ $import->completed_snaps_count }}</x-ui.td>
            <x-ui.td class="hidden sm:table-cell">{{ $import->failed_snaps_count }}</x-ui.td>
            <x-ui.td align="right">
              <div class="flex justify-end gap-2">
                <x-ui.button size="sm" variant="secondary" href="{{ url('templates/'.$template->uuid.'/imports/'.$import->uuid) }}">View</x-ui.button>
                @if (in_array($import->status, [\App\Models\SnapImport::STATUS_PENDING, \App\Models\SnapImport::STATUS_PROCESSING]))
                  <x-ui.button size="sm" variant="secondary" type="button" :onclick="$monitor">Track</x-ui.button>
                @endif
              </div>
            </x-ui.td>
          </tr>
        @endforeach
      </x-ui.table>

      @if ($imports->hasPages())
        <div class="mt-4">
          {{ $imports->links() }}
        </div>
      @endif
    @else
      <x-ui.empty-state icon="arrow-up-tray" title="Start with a new spreadsheet">
          Prepare your rows, add the webhook if needed, and kick off your first batch.
          <x-slot name="actions">
            <x-ui.button size="sm" href="{{ url('templates/'.$template->uuid.'/imports/create') }}">Create new import</x-ui.button>
          </x-slot>
      </x-ui.empty-state>
    @endif
  </section>

  <snap-import-progress-modal></snap-import-progress-modal>
</x-dashboard-layout>
