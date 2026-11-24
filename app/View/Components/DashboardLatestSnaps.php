<?php

namespace App\View\Components;

use App\Models\Snap;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class DashboardLatestSnaps extends Component
{
    public $snaps;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->snaps = Snap::where('user_id', Auth::id())->latest('id')->take(4)->get();
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.dashboard-latest-snaps');
    }
}
