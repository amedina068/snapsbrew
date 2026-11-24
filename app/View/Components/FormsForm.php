<?php

namespace App\View\Components;

use App\Models\Template;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FormsForm extends Component
{
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(
        public Template $template,
        public array $layers,
        public $form = null,
        public bool $edit = false,
        public array $selectedLayers = []
    ) {}

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.forms-form');
    }
}
