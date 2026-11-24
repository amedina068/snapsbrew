<?php

namespace App\Http\Controllers;

use App\Actions\Instance\CreateInstanceOwner;
use App\Jobs\InstallStarterTemplates;
use App\Support\InstanceSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The first-run page of a self-hosted instance. It creates the owner account
 * and, when the checkbox is on, queues the starter templates for the owner.
 * After the first user exists, both routes return 404.
 */
class SetupController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(InstanceSetup::isNeeded(), 404);

        return view('auth.setup', [
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function store(Request $request, CreateInstanceOwner $owners): RedirectResponse
    {
        abort_unless(InstanceSetup::isNeeded(), 404);

        $input = $request->validate([
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
            'company' => ['nullable', 'string', 'max:255'],
            'starter_templates' => ['sometimes', 'boolean'],
        ]);

        if (! InstanceSetup::tokenMatches($input['token'])) {
            throw ValidationException::withMessages([
                'token' => 'The setup token is not correct. Copy the token from the log of the web container.',
            ]);
        }

        $owner = $owners->createFirstOwner($input);

        // Another request created the first account while this one waited.
        abort_if($owner === null, 404);

        if ($request->boolean('starter_templates')) {
            InstallStarterTemplates::dispatch($owner);
        }

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }
}
