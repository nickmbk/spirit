<?php

namespace App\Http\Controllers;

use App\Services\Suno\SunoCallbackHandler;
use Illuminate\Http\Request;

class SunoWebhookController extends Controller
{
    public function __invoke(Request $request, SunoCallbackHandler $handler)
    {
        // Keep it quick; heavy lifting goes to queued jobs
        $handler->handle($request->all());
        return response()->json(['ok' => true]);
    }
}
