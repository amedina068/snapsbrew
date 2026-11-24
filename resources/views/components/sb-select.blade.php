{{-- Kept for App\View\Components\SbSelect. New code uses <x-ui.select>. --}}
<x-ui.select :id="$id" :name="$name" :onchange="$onchange" class="mt-1">{{ $slot }}</x-ui.select>
