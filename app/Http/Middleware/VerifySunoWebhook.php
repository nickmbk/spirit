<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifySunoWebhook
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->query('token') !== config('services.suno.webhook_token')) {
            return response()->json(['error' => 'unauthorised'], 401);
        }
        return $next($request);
    }
}
