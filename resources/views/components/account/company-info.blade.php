<div class="grid gap-6 lg:grid-cols-3">
  <div class="lg:col-span-2">
    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-xs">
      <h2 class="text-lg font-semibold text-gray-900">Profile</h2>
      <dl class="mt-6 space-y-4 text-sm text-gray-700">
        <div class="flex items-start justify-between">
          <dt class="text-gray-500">Company name</dt>
          <dd class="font-medium text-gray-900">{{ $company->name }}</dd>
        </div>
        <div class="flex items-start justify-between">
          <dt class="text-gray-500">Created at</dt>
          <dd class="font-medium text-gray-900">{{ optional($company->created_at)->toFormattedDateString() }}</dd>
        </div>
        <div class="flex items-start justify-between">
          <dt class="text-gray-500">Your email</dt>
          <dd class="font-medium text-gray-900">{{ Auth::user()->email }}</dd>
        </div>
      </dl>
    </div>
  </div>

  <div class="grid gap-4">
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-xs">
      <p class="text-sm font-medium text-gray-500">Renders (last 7 days)</p>
      <p class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format($companyRendersLast7Days) }}</p>
    </div>
  </div>
</div>
