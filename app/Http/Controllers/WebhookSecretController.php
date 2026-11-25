<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebhookSecretController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $company = $request->user()->company;

        $this->authorize('rotateWebhookSecret', $company);

        $company->rotateWebhookSecret();

        return back()->with([
            'status' => 'Webhook signing secret rotated. Update your receivers now.',
            'open_tab' => 'developers',
        ]);
    }
}
