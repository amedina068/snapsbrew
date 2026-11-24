<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class DashboardTopbar extends Component
{
    public Collection $profileItems;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->profileItems = collect([
            ['url' => url('account'), 'text' => 'Account'],
            ['url' => url('logs'), 'text' => 'Usage logs'],
        ]);
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view('components.dashboard-topbar');
    }
}
