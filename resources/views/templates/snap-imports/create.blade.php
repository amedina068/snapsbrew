<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">
    <div class="space-y-2">
      <x-ui.breadcrumb
        :items="[
          ['label' => 'Templates', 'href' => url('templates/')],
          ['label' => $template->title, 'href' => url('templates/'.$template->uuid)],
          ['label' => 'Imports', 'href' => url('templates/'.$template->uuid.'/imports')],
        ]"
        :home-url="url('/dashboard')"
      />
      <h1 class="text-lg tracking-wide text-gray-900">New import</h1>
    </div>
  </x-slot>

  <x-slot name="pageActions">
    <x-ui.button variant="secondary" icon="arrow-down-tray" href="{{ url('templates/'.$template->uuid.'/imports/export') }}">Download import template</x-ui.button>
  </x-slot>

  <div class="mx-auto mt-4 max-w-5xl space-y-6">
    <x-ui.panel title="How it works">
      <ol class="space-y-3 text-sm text-gray-600">
        @foreach ([
          'Download the import template. Its columns are the layer keys of this template.',
          'Update or add rows — each column maps to a layer key.',
          'Upload the file here to start the import batch.',
        ] as $step)
          <li class="flex gap-3">
            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-rose-50 text-xs font-bold text-rose-700">{{ $loop->iteration }}</span>
            <span class="pt-0.5">{{ $step }}</span>
          </li>
        @endforeach
      </ol>
      <div class="mt-6 rounded-lg border-l-4 border-l-rose-400 bg-rose-50 px-4 py-3 text-sm text-rose-950">
        <p class="font-semibold">Webhook reminders</p>
        <p class="mt-1">
          We will POST a <code class="rounded bg-white px-1 py-0.5 text-xs">snap.completed</code> or
          <code class="rounded bg-white px-1 py-0.5 text-xs">snap.failed</code> event for every row of the file, plus a <code class="rounded bg-white px-1 py-0.5 text-xs">snap-import.completed</code>,
          <code class="rounded bg-white px-1 py-0.5 text-xs">snap-import.failed</code>, or
          <code class="rounded bg-white px-1 py-0.5 text-xs">snap-import.canceled</code> event when the batch finishes.
        </p>
      </div>
    </x-ui.panel>

    <x-ui.panel title="Set up your import" description="Give this batch a recognizable title, optionally provide a webhook URL, then upload the spreadsheet that has all of your rows.">
      <snap-import-uploader
        upload-url="{{ url('templates/'.$template->uuid.'/imports') }}"
        collect-metadata
      ></snap-import-uploader>
      <p class="mt-4 text-xs leading-5 text-gray-500">
        We will queue a snap for every row in the file. You will receive an email and (if configured) webhook
        notifications as the snaps complete and when the import finishes.
      </p>
    </x-ui.panel>
  </div>

  <snap-import-progress-modal></snap-import-progress-modal>
</x-dashboard-layout>
