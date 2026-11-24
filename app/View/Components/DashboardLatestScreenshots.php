<?php

namespace App\View\Components;

use App\Models\Screenshot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class DashboardLatestScreenshots extends Component
{
    public $screenshots;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->screenshots = Screenshot::query()
            ->latest('id')
            ->where('user_id', Auth::id())
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
        return view('components.dashboard-latest-screenshots');
    }
}
