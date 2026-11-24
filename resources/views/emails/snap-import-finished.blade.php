@component('mail::message')
# Snap import update

Your import for **{{ $template?->title ?? 'your template' }}** is now **{{ ucfirst($status) }}**.

@component('mail::panel')
- Import ID: {{ $import->uuid }}
- Total rows: {{ $progress['total_rows'] }}
- Processed rows: {{ $progress['processed_rows'] }}
- Completed snaps: {{ $progress['completed'] }}
- Pending snaps: {{ $progress['pending'] }}
- Failed snaps: {{ $progress['failed'] }}
- Canceled snaps: {{ $progress['canceled'] }}
@endcomponent

@if($status === \App\Models\SnapImport::STATUS_FAILED)
We encountered an error while processing this import. Please review the import in the dashboard for additional details.
@elseif($status === \App\Models\SnapImport::STATUS_CANCELED)
You canceled this import. Any pending snaps were halted.
@else
You can view the generated snaps from your dashboard.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
