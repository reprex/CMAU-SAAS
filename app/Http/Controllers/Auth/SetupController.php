<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * First-run only (no shell on the server): creates the first admin when the
 * users table is empty and SETUP_TOKEN in .env matches. Gone once a user exists.
 */
class SetupController extends Controller
{
    public function show()
    {
        abort_if(User::query()->exists() || (string) config('app.setup_token') === '', 404);

        return view('auth.setup');
    }

    public function store(Request $request)
    {
        abort_if(User::query()->exists() || (string) config('app.setup_token') === '', 404);

        $data = $request->validate([
            'setup_token' => 'required|string',
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'password' => 'required|string|min:12|confirmed',
        ]);
        abort_unless(hash_equals((string) config('app.setup_token'), $data['setup_token']), 403);

        $user = User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password'], 'role' => 'admin']);
        Auth::login($user);
        $request->session()->regenerate();
        AuditLog::record('user.first_admin_created', $user);

        return redirect()->route('two-factor.setup');
    }
}
