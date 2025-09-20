<?php
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/google/auth', function () {
    $c = new GoogleClient();
    $c->setClientId(config('services.google_drive.client_id'));
    $c->setClientSecret(config('services.google_drive.client_secret'));
    $c->setRedirectUri(config('services.google_drive.oauth_redirect_uri')); // http://127.0.0.1:8000/google/callback
    $c->setAccessType('offline');
    $c->setPrompt('consent select_account');
    $c->setScopes([GoogleDrive::DRIVE_FILE]);

    $state = Str::random(32);

    // Store state in a cookie (most reliable in dev)
    Cookie::queue(cookie(
        name: 'google_oauth_state',
        value: $state,
        minutes: 10,
        path: null,
        domain: null,
        secure: false,
        httpOnly: true,
        raw: false,
        sameSite: 'Lax' // Lax works for top-level redirects
    ));

    // Also mirror to session as a fallback (optional)
    session(['google_oauth_state' => $state]);

    return redirect($c->createAuthUrl() . '&state=' . $state);
});

Route::get('/google/callback', function () {
    $reqState    = (string) request('state');
    $cookieState = (string) request()->cookie('google_oauth_state');
    $sessState   = (string) session('google_oauth_state');

    // In dev, don't 419 — just log a warning and carry on so you can mint the token.
    if (!hash_equals($reqState, $cookieState ?: $sessState)) {
        Log::warning('Google OAuth state mismatch', [
            'req'    => $reqState,
            'cookie' => $cookieState,
            'sess'   => $sessState,
        ]);
        // In production you would: abort(419, 'Bad state');
    }

    $c = new GoogleClient();
    $c->setClientId(config('services.google_drive.client_id'));
    $c->setClientSecret(config('services.google_drive.client_secret'));
    $c->setRedirectUri(config('services.google_drive.oauth_redirect_uri'));

    // Exchange code for tokens
    $token = $c->fetchAccessTokenWithAuthCode(request('code'));

    return response()->json([
        'refresh_token' => $token['refresh_token'] ?? null,
        'note' => ($token['refresh_token'] ?? null)
            ? 'Paste this into GOOGLE_DRIVE_REFRESH_TOKEN in your .env'
            : 'No refresh_token returned. Revoke previous grant at myaccount.google.com/permissions and try again.',
    ]);
});
