<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        // Already authenticated — redirect to dashboard.
        if (session('authenticated') === true) {
            return redirect('/');
        }

        return Inertia::render('Login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $expected = config('polymarket.dashboard_password');

        if ($request->input('password') !== $expected) {
            return back()->withErrors(['password' => 'Invalid password.']);
        }

        $request->session()->put('authenticated', true);
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        $request->session()->regenerate();

        return redirect()->route('login');
    }
}
