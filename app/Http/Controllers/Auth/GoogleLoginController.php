<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateGoogleZkLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreGoogleLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class GoogleLoginController extends Controller
{
    /**
     * Start a session from a Google zkLogin token.
     */
    public function store(StoreGoogleLoginRequest $request, AuthenticateGoogleZkLogin $authenticate): RedirectResponse
    {
        $user = $authenticate->handle($request->string('jwt')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
