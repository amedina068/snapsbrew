<x-dashboard-layout>
  <x-slot name="pageTitle">Dashboard</x-slot>

  <div>
    @if ($brewingShowcase)
      <div class="mb-8 flex items-start gap-4 rounded-xl border-l-4 border-l-amber-400 bg-amber-50 px-5 py-4" data-brewing-showcase role="status">
        <span class="mt-0.5 size-5 shrink-0 animate-spin rounded-full border-[3px] border-amber-200 border-t-amber-600" aria-hidden="true"></span>
        <div>
          <h2 class="font-semibold text-gray-900">Brewing your sample snap…</h2>
          <p class="mt-1 text-sm text-gray-700">We're rendering a personalized example based on your picks. It'll show up here in a few seconds — refresh the page to see it.</p>
        </div>
      </div>
    @elseif (! $hasCreatedSnap)
      <x-ui.empty-state class="mb-8" icon="sparkles" title="Create your own snap">
        Open a template and hit Generate to render a real snap in seconds.
        <x-slot name="actions">
          <x-ui.button href="/templates" icon="arrow-right">Start</x-ui.button>
        </x-slot>
      </x-ui.empty-state>
    @endif

    @if ($hasActivity)
      <div class="mt-10 space-y-16">
        <x-dashboard-latest-templates />

        @if ($hasSnaps)
          <x-dashboard-latest-snaps />
        @else
          <x-ui.empty-state icon="photo" title="Make your first snap">
            Run a snap from a saved template or trigger it directly from the API.
            <x-slot name="actions">
              <x-ui.button size="sm" href="/snapshots">Open snaps</x-ui.button>
              <x-ui.button size="sm" variant="ghost" href="/documentation/snaps">Read docs</x-ui.button>
            </x-slot>
          </x-ui.empty-state>
        @endif

        @if ($hasScreenshots)
          <x-dashboard-latest-screenshots />
        @else
          <x-ui.empty-state icon="camera" title="Capture your first screenshot">
            Spin up a quick capture from the form or wire it into your automation.
            <x-slot name="actions">
              <x-ui.button size="sm" href="/screenshots/new">New screenshot</x-ui.button>
              <x-ui.button size="sm" variant="ghost" href="/documentation/screenshots">Read guide</x-ui.button>
            </x-slot>
          </x-ui.empty-state>
        @endif
      </div>
    @else
      <x-empty-activity />
    @endif
  </div>

  @if ($brewingShowcase)
    <script>
      (function () {
        var attempts = parseInt(sessionStorage.getItem('brewingShowcaseAttempts') || '0', 10);
        if (attempts >= 12) return;
        sessionStorage.setItem('brewingShowcaseAttempts', String(attempts + 1));
        setTimeout(function () { window.location.reload(); }, 10000);
      })();
    </script>
  @else
    <script>sessionStorage.removeItem('brewingShowcaseAttempts');</script>
  @endif

</x-dashboard-layout>
