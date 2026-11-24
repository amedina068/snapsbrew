<section>
  <x-ui.section-heading title="Most used Templates" href="/templates" />
  <div class="mt-4 grid gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
    @foreach ($templates as $template)
      <x-template-card :template="$template" />
    @endforeach
  </div>
</section>
