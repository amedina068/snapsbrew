<x-dashboard-layout>
  <x-slot name="pageTitle">Snaps</x-slot>

  @if ($snapshots->isEmpty())
    <section class="mt-6" aria-labelledby="first-snap-heading">
      <h2 id="first-snap-heading" class="text-xl font-semibold text-gray-900">Make your first snap</h2>
      <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.action-card href="/documentation/snaps" icon="book-open" title="Generate via API" cta="Read docs">
          Send a single POST request to render on-brand assets at scale.
        </x-ui.action-card>
        <x-ui.action-card href="/templates" icon="bolt" title="Generate from a template" cta="Open templates">
          Open a template, type the text and generate a snap in the browser.
        </x-ui.action-card>
        <x-ui.action-card href="/documentation/guides/embedded-forms" icon="document-text" title="Share a form" cta="Read the guide">
          Let teammates generate snaps with a form that you embed in a page.
        </x-ui.action-card>
      </div>
    </section>
  @else
    <div class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
      @foreach ($snapshots as $snapshot)
        <x-snap-item :snap="$snapshot" />
      @endforeach
    </div>

    <div class="mt-8">
      {{ $snapshots->links() }}
    </div>
  @endif
</x-dashboard-layout>
