<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateSlushLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreSlushLoginRequest;
use App\Services\Slush\SlushLoginChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SlushLoginController extends Controller
{
    /**
     * Give the browser the exact message Slush must sign.
     */
    public function challenge(Request $request, SlushLoginChallenge $challenge): JsonResponse
    {
        return response()->json([
            'message' => $challenge->issue($request->session()),
        ]);
    }

    /**
     * Start a session from a Slush personal-message signature.
     */
    public function store(StoreSlushLoginRequest $request, AuthenticateSlushLogin $authenticate): RedirectResponse
    {
        $user = $authenticate->handle($request, $request->string('signature')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
