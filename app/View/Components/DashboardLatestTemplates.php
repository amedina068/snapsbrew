<?php

namespace App\View\Components;

use App\Models\Template;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class DashboardLatestTemplates extends Component
{
    public $templates;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->templates = Template::where('user_id', Auth::id())
            ->select('id', 'uuid', 'title')
            ->withCount('snaps')
            ->orderBy('snaps_count', 'DESC')
            ->take(4)
            ->get();
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.dashboard-latest-templates');
    }
}
