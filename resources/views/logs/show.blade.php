<x-dashboard-layout vue-slot>
  <x-slot name="pageTitle">Usage Log</x-slot>

  <div class="mt-6 space-y-6">
    <x-ui.breadcrumb :items="$breadcrumbs" />

    {{-- The request and response viewers are Vue components. The summary prints user text, so Vue does not compile it. --}}
    <div v-pre class="rounded-2xl border border-gray-200 bg-white p-6 shadow-xs">
      <dl class="grid gap-6 md:grid-cols-2">
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Counts towards period usage</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->status_code == 201 ? 'Yes' : 'No' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Date</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->created_at->format('M d, Y H:i') }}
          </dd>
          <p class="text-xs text-gray-500">{{ $apiLog->created_at->diffForHumans() }}</p>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Renders</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->renders ?? '0' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">HTTP method</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->method }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Type</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->type ?? '—' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status code</dt>
          <dd class="mt-1 text-lg font-semibold text-gray-900">
            {{ $apiLog->status_code ?? '—' }}
          </dd>
        </div>
      </dl>
    </div>

    <div v-pre class="rounded-2xl border border-gray-200 bg-white p-6 shadow-xs">
      <h3 class="text-lg font-semibold text-gray-900">Related resources</h3>
      <dl class="mt-4 grid gap-4 md:grid-cols-2">
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Template</dt>
          <dd class="mt-1 text-sm text-gray-900">
            @if($template && $templateLink)
              <a href="{{ $templateLink }}" class="text-rose-700 underline">
                {{ $template->title }}
              </a>
            @else
              <span class="text-gray-500">Not available</span>
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Snap</dt>
          <dd class="mt-1 text-sm text-gray-900">
            @if($snap && $snapLink)
              <a href="{{ $snapLink }}" class="text-rose-700 underline">
                {{ $snap->uuid }}
              </a>
            @else
              <span class="text-gray-500">Not available</span>
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Form</dt>
          <dd class="mt-1 text-sm text-gray-900">
            @if($form && $formLink)
              <a href="{{ $formLink }}" class="text-rose-700 underline">
                {{ $form->title }}
              </a>
            @else
              <span class="text-gray-500">Not available</span>
            @endif
          </dd>
        </div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">API key</dt>
          <dd class="mt-1 text-sm text-gray-900">
            @if($apiKey)
              <span class="font-semibold text-gray-900">{{ $apiKey->name ?? 'API token' }}</span>
              <p class="text-xs text-gray-500">Token #{{ $apiKey->id }}</p>
            @else
              <span class="text-gray-500">Not available</span>
            @endif
          </dd>
        </div>
      </dl>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
      <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-xs">
        <p class="text-lg font-semibold text-gray-900">Request</p>
        <div class="mt-4">
          <pretty-json :json="{{ $apiLog->request }}"></pretty-json>
        </div>
      </div>
      <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-xs">
        <p class="text-lg font-semibold text-gray-900">Response</p>
        <div class="mt-4">
          <pretty-json :json="{{ $apiLog->response }}"></pretty-json>
        </div>
      </div>
    </div>
  </div>
</x-dashboard-layout>
