<?php

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Illuminate\Support\Facades\Route;

Route::get('/google/auth', function () {
    $client = new GoogleClient();
    $client->setClientId(config('google.client_id'));
    $client->setClientSecret(config('google.client_secret'));
    $client->setRedirectUri(config('google.oauth_redirect_uri')); // must match Google Console exactly
    $client->setAccessType('offline');                 // we want a refresh token
    $client->setPrompt('consent select_account');      // force consent so we get RT
    $client->setScopes([GoogleDrive::DRIVE_FILE]);

    session(['google_oauth_state' => $state = bin2hex(random_bytes(16))]);
    return redirect($client->createAuthUrl() . '&state=' . $state);
});

Route::get('/google/callback', function () {
    abort_unless(request('state') === session('google_oauth_state'), 419, 'Bad state');

    $client = new GoogleClient();
    $client->setClientId(config('google.client_id'));
    $client->setClientSecret(config('google.client_secret'));
    $client->setRedirectUri(config('google.oauth_redirect_uri'));

    $token = $client->fetchAccessTokenWithAuthCode(request('code'));

    return response()->json([
        // access_token omitted on purpose; you only need the refresh_token long-term
        'refresh_token' => $token['refresh_token'] ?? null,
        'note' => ($token['refresh_token'] ?? null)
            ? 'Copy this into GOOGLE_DRIVE_REFRESH_TOKEN in your .env'
            : 'No refresh_token returned. Revoke previous grant at myaccount.google.com/permissions and try again.',
    ]);
});
