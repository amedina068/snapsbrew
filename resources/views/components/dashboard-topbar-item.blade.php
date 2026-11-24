<a href="{{ $href }}" @if ($target) target="{{ $target }}" @endif
   @if ($isActive) aria-current="page" @endif
   class="{{ $isActive ? 'border-rose-500 font-semibold text-rose-600' : 'border-transparent text-gray-500 hover:border-rose-300 hover:text-rose-500' }} inline-flex items-center border-b-2 px-1 text-sm transition-colors">{{ $slot }}</a>
