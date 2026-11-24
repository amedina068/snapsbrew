<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SbTableBodyCol extends Component
{
    public $initial;

    public $right;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($initial = false, $right = false)
    {
        $this->initial = $initial;
        $this->right = $right;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.sb-table-body-col');
    }
}
