<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramBroadcast;
use App\Models\User;
use App\Services\Admin\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramController extends Controller
{
    /** Segmen penerima broadcast. */
    private const AUDIENCES = [
        'all'      => 'Semua pengguna Telegram',
        'active'   => 'Aktif dalam 30 hari terakhir',
        'vip'      => 'Anggota berlangganan aktif',
        'inactive' => 'Belum aktif lebih dari 30 hari',
    ];

    public function index(): View
    {
        return view('web.pages.admin.telegram', [
            'bot'        => $this->botInfo(),
            'webhook'    => $this->webhookInfo(),
            'audiences'  => self::AUDIENCES,
            'counts'     => collect(self::AUDIENCES)
                ->mapWithKeys(fn ($label, $key) => [$key => $this->audience($key)->count()])
                ->all(),
            'stats' => [
                'total'    => User::whereNotNull('telegram_id')->count(),
                'active'   => User::whereNotNull('telegram_id')->where('is_active', true)->count(),
                'banned'   => User::whereNotNull('telegram_id')->where('is_banned', true)->count(),
                'today'    => User::whereNotNull('telegram_id')->whereDate('created_at', today())->count(),
            ],
        ]);
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:'.implode(',', array_keys(self::AUDIENCES))],
            'message'  => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        if (blank(config('telegram.bot_token'))) {
            return back()->withErrors(['message' => 'Token bot belum diisi di .env.'])->withInput();
        }

        $recipients = $this->audience($data['audience'])
            ->whereNotNull('telegram_id')
            ->where('is_banned', false)
            ->pluck('telegram_id', 'id');

        if ($recipients->isEmpty()) {
            return back()->withErrors(['audience' => 'Tidak ada penerima pada segmen ini.'])->withInput();
        }

        // Disebar ke antrean supaya permintaan HTTP tidak menunggu ribuan
        // panggilan API Telegram selesai.
        foreach ($recipients as $userId => $telegramId) {
            SendTelegramBroadcast::dispatch($telegramId, $data['message'], $userId);
        }

        app(ActivityLogger::class)->log('broadcast', 'telegram', null, [
            'segmen'  => $data['audience'],
            'jumlah'  => $recipients->count(),
        ]);

        return back()->with(
            'status',
            "Broadcast diantrekan untuk {$recipients->count()} pengguna. Pastikan worker antrean berjalan."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    private function audience(string $key)
    {
        $query = User::query()->where('is_admin', false)->whereNotNull('telegram_id');

        return match ($key) {
            'active'   => $query->where('last_seen_at', '>=', now()->subDays(30)),
            'inactive' => $query->where(fn ($q) => $q
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subDays(30))),
            'vip'      => $query->whereHas('subscriptions', fn ($q) => $q
                ->where('status', 'active')
                ->where(fn ($w) => $w->whereNull('expired_at')->orWhere('expired_at', '>', now()))),
            default    => $query,
        };
    }

    /** Informasi bot dari Telegram. Null bila token belum diisi atau API gagal. */
    private function botInfo(): ?array
    {
        return $this->call('getMe');
    }

    private function webhookInfo(): ?array
    {
        return $this->call('getWebhookInfo');
    }

    private function call(string $method): ?array
    {
        $token = config('telegram.bot_token');

        if (blank($token)) {
            return null;
        }

        try {
            $response = Http::timeout(6)
                ->get(config('telegram.api_url').'/bot'.$token.'/'.$method);

            return $response->json('result') ?: null;
        } catch (\Throwable) {
            // Jaringan bermasalah tidak boleh membuat halaman admin error.
            return null;
        }
    }
}
