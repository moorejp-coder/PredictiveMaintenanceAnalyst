<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * The signed-in user's account page. One action: sign out of every other
 * browser. The routes sit behind the sign-in switch (EnsureLoginEnabled) and
 * the auth middleware, so a demo visitor never reaches them.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account', ['email' => $request->user()->email]);
    }

    public function logoutEverywhere(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->getAuthPassword())) {
            return back()->withErrors(['password' => 'The password is wrong.']);
        }

        // Saves a fresh hash of the same password. Every other session holds
        // the old hash, so AuthenticateSession ends each one on its next
        // request. This browser keeps its session.
        Auth::logoutOtherDevices($data['password']);

        return redirect('/account')->with('status', 'Every other browser is signed out. This one stays signed in.');
    }
}
