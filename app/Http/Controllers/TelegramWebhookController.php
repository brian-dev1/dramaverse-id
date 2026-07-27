<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Telegram\Router\TelegramRouter;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        app(TelegramRouter::class)
            ->dispatch($request->all());

        return response()->json([
            'ok' => true,
        ]);
    }
} 