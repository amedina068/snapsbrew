@props(['errors'])

@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'rounded-xl border-[3px] border-atomic-ink bg-atomic-coral p-4']) }}>
        <div class="font-display text-sm uppercase tracking-wide text-atomic-cream">
            {{ __('Whoops! Something went wrong.') }}
        </div>

        <ul class="mt-2 list-inside list-disc text-sm text-atomic-cream/90">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
