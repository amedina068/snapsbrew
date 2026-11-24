<?php

namespace App\View\Components;

use App\Models\Snap;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SnapItem extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(public Snap $snap)
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.snap-item');
    }
}
