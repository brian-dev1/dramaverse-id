<?php

namespace App\Http\Controllers;

use App\Services\Telegram\Exceptions\TelegramException;
use App\Telegram\Router\TelegramRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            app(TelegramRouter::class)->dispatch($request->all());
        } catch (TelegramException) {

            /*
            |------------------------------------------------------------------
            | Kegagalan balasan tidak boleh jadi jawaban gagal
            |------------------------------------------------------------------
            |
            | Sejak Sprint 8.1 lapisan Telegram melempar kegagalan, bukan
            | mengembalikan array yang boleh diabaikan. Kalau exception itu
            | dibiarkan lolos ke sini, Laravel menjawab 500 — dan Telegram
            | membaca jawaban selain 2xx sebagai "update belum diproses",
            | lalu mengirimkannya lagi. Berulang-ulang, dengan update yang
            | sama, sementara penyebabnya (pengguna memblokir bot) tidak akan
            | pernah berubah.
            |
            | Update-nya memang sudah diproses; yang gagal hanya balasannya.
            | Sebabnya sudah dicatat TelegramClient dengan lengkap, jadi
            | jawaban ok di sini tidak menyembunyikan apa pun.
            |
            */
        }

        return response()->json(['ok' => true]);
    }
}
