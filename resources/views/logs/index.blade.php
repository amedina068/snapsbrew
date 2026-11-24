<x-dashboard-layout>
  <x-slot name="pageTitle">Usage Logs</x-slot>

  <div class="mt-6 space-y-6">
    <x-ui.breadcrumb :items="$breadcrumbs" />

    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-xs">
      <x-sb-table>
        <x-slot name="head">
          <x-sb-table-head-col initial> Date </x-sb-table-head-col>
          <x-sb-table-head-col> Method </x-sb-table-head-col>
          <x-sb-table-head-col> Type </x-sb-table-head-col>
          <x-sb-table-head-col> Status </x-sb-table-head-col>
          <x-sb-table-head-col right> Renders </x-sb-table-head-col>
        </x-slot>
        <x-slot name="body">
            @foreach($apiLogs as $apiLog)
                <tr>
                  <x-sb-table-body-col initial>
                    <div>
                      <a href="/logs/{{ $apiLog->uuid }}" class="font-semibold text-gray-900 hover:text-rose-700">
                        {{ $apiLog->created_at->format('M d, Y H:i') }}
                      </a>
                      <p class="text-xs text-gray-500">{{ $apiLog->created_at->diffForHumans() }}</p>
                    </div>
                  </x-sb-table-body-col>
                  <x-sb-table-body-col>
                    {{ $apiLog->method }}
                  </x-sb-table-body-col>
                  <x-sb-table-body-col>
                    {{ $apiLog->type }}
                  </x-sb-table-body-col>
                  <x-sb-table-body-col>
                    {{ $apiLog->status_code }}
                  </x-sb-table-body-col>
                  <x-sb-table-body-col right>
                    {{ $apiLog->renders }}
                  </x-sb-table-body-col>
                </tr>
            @endforeach
        </x-slot>
      </x-sb-table>
    </div>

    <div>
      {{ $apiLogs->links() }}
    </div>
  </div>
</x-dashboard-layout>
