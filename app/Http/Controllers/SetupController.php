<?php

namespace App\Http\Controllers;

use App\Actions\Instance\CreateInstanceOwner;
use App\Jobs\GenerateTemplatePreview;
use App\Models\User;
use App\Services\StarterTemplateInstaller;
use App\Support\InstanceSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * The first-run page of a self-hosted instance. It creates the owner account
 * and, when the checkbox is on, adds the starter templates for the owner.
 * After the first user exists, both routes return 404.
 */
class SetupController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(InstanceSetup::isNeeded(), 404);

        $token = (string) $request->query('token', '');
        $tokenIsValid = InstanceSetup::tokenMatches($token);

        // A wrong token never goes back into the page.
        return view('auth.setup', [
            'token' => $tokenIsValid ? $token : '',
            'tokenGiven' => $token !== '',
            'tokenIsValid' => $tokenIsValid,
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
                'token' => 'The setup token is not correct. Copy the setup URL from the container log again.',
            ]);
        }

        $owner = $owners->createFirstOwner($input);

        // Another request created the first account while this one waited.
        abort_if($owner === null, 404);

        if ($request->boolean('starter_templates')) {
            $this->addStarterTemplates($owner);
        }

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * The templates go in now, so the first dashboard load shows them. The
     * preview images render on the queue. A failed template or preview must
     * not stop the setup, because the owner already exists. The owner can add
     * the templates later with `snapsbrew:starter-templates`.
     */
    private function addStarterTemplates(User $owner): void
    {
        try {
            $created = app(StarterTemplateInstaller::class)->install($owner, renderPreviews: false)['created'];
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        // With the sync queue, each job runs here, so one failed preview
        // must not stop the next one.
        foreach ($created as $template) {
            try {
                GenerateTemplatePreview::dispatch($template);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
