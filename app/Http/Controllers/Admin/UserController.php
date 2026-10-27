<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->get(),
            'audit' => AuditLog::query()->with('user')->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'role' => ['required', Rule::in(User::ROLES)],
            'password' => 'required|string|min:12',
        ]);
        $data['email'] = mb_strtolower($data['email']);
        $user = User::create($data);
        AuditLog::record('user.created', $user, ['role' => $user->role]);

        return back()->with('status', "{$user->name} added. They set up two-factor sign-in the first time they log in.");
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)], 'active' => 'boolean']);
        $data['active'] = $request->boolean('active');

        if ($user->is($request->user()) && ($data['role'] !== 'admin' || ! $data['active'])) {
            return back()->withErrors(['role' => 'You cannot remove your own admin access.']);
        }

        $user->update($data);
        AuditLog::record('user.updated', $user, $data);

        return back()->with('status', "{$user->name} updated.");
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLog::record('user.two_factor_reset', $user);

        return back()->with('status', "{$user->name} will set up two-factor sign-in again at their next login.");
    }
}
