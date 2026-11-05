<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Divisions;
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

    /** The access fields: one level (or none) per division and for the shared modules. */
    private function accessRules(): array
    {
        $rules = ['access' => 'array'];
        foreach ([...Divisions::keys(), Divisions::SHARED] as $d) {
            $rules["access.{$d}"] = ['nullable', Rule::in(array_keys(config('divisions.levels')))];
        }

        return $rules;
    }

    /** @return array<string, ?string> */
    private function access(Request $request): array
    {
        $out = [];
        foreach ([...Divisions::keys(), Divisions::SHARED] as $d) {
            $out[$d] = $request->input("access.{$d}") ?: null;
        }

        return $out;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:12',
        ] + $this->accessRules());
        $divisions = $this->access($request);
        $user = User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'],
            'role' => in_array('admin', $divisions, true) ? 'admin' : (in_array('staff', $divisions, true) ? 'sales' : 'readonly'), 'divisions' => $divisions]);
        AuditLog::record('user.created', $user, ['divisions' => $divisions]);

        return back()->with('status', "{$user->name} added. They set up two-factor sign-in the first time they log in.");
    }

    public function update(Request $request, User $user)
    {
        $request->validate(['active' => 'boolean'] + $this->accessRules());
        $divisions = $this->access($request);
        $active = $request->boolean('active');

        if ($user->is($request->user()) && ($divisions[Divisions::SHARED] !== 'admin' || ! $active)) {
            return back()->withErrors(['access' => 'You cannot remove your own Users & access admin rights.']);
        }

        $before = [...Divisions::keys(), Divisions::SHARED];
        $before = collect($before)->mapWithKeys(fn ($d) => [$d => $user->access($d)])->all();
        $user->update(['divisions' => $divisions, 'active' => $active]);
        AuditLog::record('user.updated', $user, ['before' => $before, 'after' => $divisions, 'active' => $active]);

        return back()->with('status', "{$user->name} updated.");
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        TrustedDevice::query()->where('user_id', $user->id)->delete(); // trusted devices and passkeys go too
        Passkey::query()->where('user_id', $user->id)->delete();
        AuditLog::record('user.two_factor_reset', $user);

        return back()->with('status', "{$user->name} will set up two-factor sign-in again at their next login.");
    }
}
