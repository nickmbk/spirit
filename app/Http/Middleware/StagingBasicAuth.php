<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StagingBasicAuth
{
    public function handle(Request $request, Closure $next)
    {
        // Only gate non-production (tweak as you like)
        if (! app()->environment('production')) {

            // Allow webhooks/health to bypass if needed
            if ($request->is('webhook/*', 'health')) {
                return $next($request);
            }

            $user = config('staging.basic_user');
            $pass = config('staging.basic_pass');

            $givenUser = $request->getUser();
            $givenPass = $request->getPassword() ?? '';

            if ($user === null || $pass === null ||
                $givenUser !== $user ||
                ! hash_equals($pass, $givenPass)) {
                return response('Unauthorised', 401)
                    ->header('WWW-Authenticate', 'Basic realm="Staging"');
            }
        }

        return $next($request);
    }
}
