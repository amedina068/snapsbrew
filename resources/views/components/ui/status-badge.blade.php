{{-- A badge for a snap or an import status. The tone comes from the status. --}}
@props(['status'])
@php
    $tone = match ((string) $status) {
        'completed' => 'success',
        'failed' => 'danger',
        'pending', 'processing' => 'warning',
        default => 'neutral',
    };
@endphp
<x-ui.badge :tone="$tone" {{ $attributes->merge(['class' => 'capitalize']) }}>{{ $status ?: 'unknown' }}</x-ui.badge>
