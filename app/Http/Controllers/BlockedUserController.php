<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Messaging;
use App\Http\Requests\BlockUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BlockedUserController extends Controller
{
    public function index(Request $request): Response
    {
        $request->validate(['page' => ['bail', 'nullable', 'integer', 'min:1', 'max:1000']]);
        $users = DB::table('blocked_users')->join('users', 'users.id', '=', 'blocked_users.blocked_user_id')->where('blocked_users.user_id', $request->user()->id)->orderByDesc('blocked_users.created_at')->orderBy('users.id')->select('users.id', 'users.name')->paginate(20);

        return Inertia::render('messages/Blocked', ['users' => $users]);
    }

    public function store(BlockUserRequest $request, User $user, Messaging $messaging): RedirectResponse
    {
        $messaging->block($request->user(), $user, true);

        return back();
    }

    public function destroy(BlockUserRequest $request, User $user, Messaging $messaging): RedirectResponse
    {
        $messaging->block($request->user(), $user, false);

        return back();
    }
}
