<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\LogFileReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pembaca log pembayaran.
 *
 * Memakai `LogFileReader` yang sama dengan Log Telegram dan Log Sistem —
 * hanya awalan penyaringnya yang berbeda. Menyalin parsernya berarti tiga
 * definisi tentang satu format log, dan yang satu akan tertinggal saat
 * formatnya berubah.
 *
 * Halaman ini ada terpisah karena sengketa pembayaran adalah pekerjaan yang
 * mendesak: yang menanyakannya sedang menunggu jawaban, dan menyembunyikan
 * penyaringnya di balik dropdown halaman lain menambah satu langkah pada
 * pekerjaan yang paling tidak boleh lambat.
 */
class PaymentLogController extends Controller
{
    private const LIMIT = 200;

    public function __construct(
        protected LogFileReader $log
    ) {
    }

    public function index(Request $request): View
    {
        $level = (string) $request->query('level', '');

        $q = trim((string) $request->query('q', ''));

        $baris = array_values(array_filter(
            $this->log->tail('payment.', 5000),
            fn (array $b) => ($level === '' || $b['level'] === $level)
                && ($q === '' || stripos($b['pesan'], $q) !== false)
        ));

        return view('web.pages.admin.payment-log', [
            'entries' => array_slice($baris, 0, self::LIMIT),
            'level'   => $level,
            'q'       => $q,
            'levels'  => ['error', 'warning', 'info'],
            'file'    => $this->log->path(),
            'ada'     => $this->log->exists(),
        ]);
    }
}
