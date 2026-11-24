<div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <x-ui.action-card href="/templates" icon="plus" title="Create your first snap" cta="Browse templates">
    Open a template and hit Generate to render a real snap in seconds.
  </x-ui.action-card>

  <x-ui.action-card href="/screenshots/new" icon="camera" title="Take a screenshot" cta="Create capture">
    Capture any page instantly and ship polished previews to stakeholders.
  </x-ui.action-card>

  <x-ui.action-card href="{{ url('documentation') }}" icon="book-open" title="API documentation" cta="Read docs">
    Review endpoints, authentication, and examples to automate everything.
  </x-ui.action-card>
</div>
