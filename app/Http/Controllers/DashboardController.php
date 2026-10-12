<?php

namespace App\Http\Controllers;

use App\Enums\OauthProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the dashboard, including the user's Sui address when one exists.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $address = $user
            ?->oauthIdentities()
            ->where('provider', OauthProvider::Google)
            ->value('sui_address');

        if (! is_string($address)) {
            $address = $user?->wallet()->value('address');
        }

        return Inertia::render('dashboard', [
            'suiAddress' => is_string($address) ? $address : null,
        ]);
    }
}
