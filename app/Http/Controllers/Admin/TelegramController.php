<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramBroadcast;
use App\Repositories\Contracts\TelegramRepositoryInterface;
use App\Services\Admin\ActivityLogger;
use App\Services\Telegram\Contracts\TelegramServiceInterface;
use App\Services\Telegram\Exceptions\TelegramException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Halaman Telegram di panel admin.
 *
 * Controller ini TIDAK memanggil Telegram API sendiri. Sebelum Sprint 8.1 ia
 * punya method `call()` berisi `Http::timeout(6)` dengan URL yang dirakit
 * dari token — jalur ketiga ke Telegram di dalam proyek ini, dengan timeout
 * dan penanganan galat yang tidak sama dengan dua jalur lainnya.
 */
class TelegramController extends Controller
{
    /**
     * Batas waktu untuk pembacaan yang menahan halaman admin, dalam detik.
     *
     * Lebih pendek daripada bawaan karena ada orang yang sedang menunggu
     * halaman terbuka, bukan job di antrean.
     */
    private const PROBE_TIMEOUT = 6;

    public function __construct(
        protected TelegramServiceInterface $telegram,
        protected TelegramRepositoryInterface $repository
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.telegram', [
            'bot'       => $this->probe('getMe'),
            'webhook'   => $this->probe('getWebhookInfo'),
            'audiences' => $this->repository->audiences(),
            'counts'    => $this->repository->counts(),
            'stats'     => $this->repository->stats(),
        ]);
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $segmen = array_keys($this->repository->audiences());

        $data = $request->validate([
            'audience' => ['required', 'string', 'in:'.implode(',', $segmen)],
            'message'  => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        if (! $this->telegram->isConfigured()) {
            return back()
                ->withErrors(['message' => 'Token bot belum diisi di .env.'])
                ->withInput();
        }

        $recipients = $this->repository->recipients($data['audience']);

        if ($recipients->isEmpty()) {
            return back()
                ->withErrors(['audience' => 'Tidak ada penerima pada segmen ini.'])
                ->withInput();
        }

        // Disebar ke antrean supaya permintaan HTTP tidak menunggu ribuan
        // panggilan API Telegram selesai.
        foreach ($recipients as $userId => $telegramId) {
            SendTelegramBroadcast::dispatch($telegramId, $data['message'], $userId);
        }

        app(ActivityLogger::class)->log('broadcast', 'telegram', null, [
            'segmen' => $data['audience'],
            'jumlah' => $recipients->count(),
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

    /**
     * Baca satu method Bot API untuk ditampilkan, atau null bila gagal.
     *
     * Halaman ini bersifat informasi. Token yang belum diisi, jaringan VPS
     * yang bermasalah, atau Telegram yang sedang tumbang tidak boleh membuat
     * seluruh halaman admin mati — semua kartu lain di halaman ini datang
     * dari database dan tetap benar tanpa Telegram.
     *
     * Pengulangan dimatikan di sini. Untuk pekerjaan di antrean, mengulang
     * itu benar; untuk halaman yang sedang ditunggu orang, mengulang hanya
     * melipatgandakan waktu tunggu sebelum kegagalan yang sama muncul.
     *
     * Kegagalannya sudah dicatat TelegramClient beserta sebabnya, jadi
     * menelannya di sini tidak menghilangkan jejak apa pun.
     */
    private function probe(string $method): ?array
    {
        if (! $this->telegram->isConfigured()) {
            return null;
        }

        try {
            return $this->telegram
                ->withTimeout(self::PROBE_TIMEOUT)
                ->withRetries(1)
                ->query($method)
                ->array() ?: null;

        } catch (TelegramException) {
            return null;
        }
    }
}
