<?php

namespace App\View\Components\Templates;

use App\Models\Template;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SelectCard extends Component
{
    public function __construct(
        public Template $template,
        public string $name = 'template_ids[]',
        public bool $checked = false,
        public string $type = 'checkbox'
    ) {}

    public function render(): View
    {
        return view('components.templates.select-card');
    }
}
