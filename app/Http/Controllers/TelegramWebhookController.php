<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, TelegramUpdateRouter $router): JsonResponse
    {
        return $router->handle($request->all(), $secret);
    }
}
