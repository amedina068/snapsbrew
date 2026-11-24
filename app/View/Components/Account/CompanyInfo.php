<?php

namespace App\View\Components\Account;

use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class CompanyInfo extends Component
{
    public function __construct(
        public Company $company,
        public int $companyRendersLast7Days = 0) {}

    public function render(): View
    {
        return view('components.account.company-info');
    }
}
