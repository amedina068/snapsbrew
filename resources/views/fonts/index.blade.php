<x-dashboard-layout>
  <x-slot name="pageTitle">Custom fonts</x-slot>

  <div class="max-w-3xl">
    <p class="mb-6 text-sm text-gray-600">
      Upload OTF, TTF, WOFF or WOFF2 files (max 5&nbsp;MB each). Use the font's family name in a text
      layer to render with it.
    </p>

    @if (session('status'))
      <div class="mb-6 rounded-lg border-l-4 border-l-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
      <div class="mb-6 rounded-lg border-l-4 border-l-red-500 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
        <ul class="list-disc space-y-1 pl-5">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <x-ui.panel title="Upload a font" class="mb-10">
      <form action="{{ url('/fonts') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <x-ui.label for="name">Display name</x-ui.label>
            <x-ui.input id="name" name="name" value="{{ old('name') }}" required class="mt-1" />
          </div>
          <div>
            <x-ui.label for="family">CSS family name</x-ui.label>
            <x-ui.input id="family" name="family" value="{{ old('family') }}" required
              pattern="[A-Za-z0-9 _\-]{1,100}"
              title="Letters, numbers, spaces, hyphen and underscore only"
              class="mt-1" />
          </div>
        </div>
        <div class="mt-4">
          <x-ui.label for="file">Font file</x-ui.label>
          <input id="file" name="file" type="file" accept=".otf,.ttf,.woff,.woff2" required
            class="focus-ring mt-1 block w-full rounded-lg text-sm text-gray-700 file:mr-3 file:rounded-full file:border file:border-gray-300 file:bg-white file:px-4 file:py-2 file:text-sm file:font-semibold file:text-gray-800 hover:file:bg-slate-50">
        </div>
        <x-ui.button class="mt-5" icon="arrow-up-tray">Upload font</x-ui.button>
      </form>
    </x-ui.panel>

    <h2 class="mb-3 text-lg font-semibold text-gray-900">Your fonts</h2>

    @if ($fonts->isEmpty())
      <x-ui.empty-state icon="document-text" title="No custom fonts yet">Upload one above to use it in your templates.</x-ui.empty-state>
    @else
      @cannot('delete', $fonts->first())
        <p id="fonts-owner-only" class="mb-3 text-sm text-gray-600">Only a company owner can delete a font.</p>
      @endcannot
      <x-ui.table caption="Your fonts">
        <x-slot name="head">
          <x-ui.th>Font</x-ui.th>
          <x-ui.th>Used by</x-ui.th>
          <x-ui.th align="right" hidden-label>Actions</x-ui.th>
        </x-slot>
        @foreach ($fonts as $font)
          @php $forced = session('force_delete_uuid') === $font->uuid; @endphp
          <tr>
            <x-ui.td strong>
              {{ $font->name }}
              <span class="block font-mono text-xs font-normal text-gray-500">{{ $font->family }}</span>
            </x-ui.td>
            <x-ui.td class="text-xs">
              @if ($font->used_by->isNotEmpty())
                <span class="text-amber-800">{{ $font->used_by->count() }} template{{ $font->used_by->count() === 1 ? '' : 's' }}: {{ $font->used_by->pluck('title')->implode(', ') }}</span>
              @else
                —
              @endif
            </x-ui.td>
            <x-ui.td align="right">
              @can('delete', $font)
                <form action="{{ url('/fonts/'.$font->uuid) }}" method="POST"
                  onsubmit="return confirm('{{ $forced ? 'Delete anyway? Templates using this font will fall back to a default.' : 'Delete this font?' }}');">
                  @csrf
                  @method('DELETE')
                  @if ($forced)
                    <input type="hidden" name="force" value="1">
                    <x-ui.button size="sm" variant="danger">Delete anyway</x-ui.button>
                  @else
                    <x-ui.button size="sm" variant="danger-ghost">Delete</x-ui.button>
                  @endif
                </form>
              @else
                <x-ui.button type="button" size="sm" variant="danger-ghost" disabled title="Only a company owner can do this." aria-describedby="fonts-owner-only">Delete</x-ui.button>
              @endcan
            </x-ui.td>
          </tr>
        @endforeach
      </x-ui.table>
    @endif
  </div>
</x-dashboard-layout>
