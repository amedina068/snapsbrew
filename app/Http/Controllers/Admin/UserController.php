<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Instance\DeleteUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $users = User::query()
            ->with('company:id,name')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function destroy(User $user, DeleteUser $deleter)
    {
        // The same rule as deactivation: an owner stays, the sender included.
        abort_if($user->isOwner(), 403, 'You cannot delete an owner.');

        $deleter->delete($user);

        return back()->with('status', "{$user->email} is deleted.");
    }
}
