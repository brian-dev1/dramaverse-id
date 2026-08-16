<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelAnnouncement;
use App\Services\Admin\ActivityLogger;
use App\Services\Admin\MediaService;
use App\Services\Telegram\ChannelAnnouncementService;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pengumuman bebas ke channel Telegram.
 *
 * Terpisah dari `ChannelPostController` walau panelnya menumpang di halaman
 * yang sama. Yang satu menyusun katalog drama dari data yang sudah ada; yang
 * ini menerima tulisan, gambar, dan tombol yang seluruhnya diketik admin.
 * Satu-satunya yang mereka bagi adalah channel tujuannya.
 */
class ChannelAnnouncementController extends Controller
{
    /** Batas jumlah tombol. Lebih dari ini, yang terbaca di ponsel cuma tumpukan. */
    public const MAX_TOMBOL = 4;

    public function __construct(
        protected ChannelAnnouncementService $service,
        protected MediaService $media
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body'             => ['required', 'string', 'min:3', 'max:4000'],
            'image_file'       => MediaService::rules(),
            'kirim'            => ['required', 'string', 'in:sekarang,jadwal'],
            'scheduled_at'     => ['required_if:kirim,jadwal', 'nullable', 'date'],
            'buttons'          => ['nullable', 'array', 'max:'.self::MAX_TOMBOL],
            'buttons.*.label'  => ['nullable', 'string', 'max:64'],
            'buttons.*.url'    => ['nullable', 'string', 'max:255'],
        ], [
            'body.required'         => 'Isi pengumumannya dulu.',
            'scheduled_at.required_if' => 'Pilih tanggal dan jam tayangnya.',
        ]);

        $tombol = $this->tombol($data['buttons'] ?? []);

        $jadwal = null;

        if ($data['kirim'] === 'jadwal') {

            /*
            | Jam yang diketik admin adalah jam LOKAL, basis data menyimpan
            | UTC. Tanpa penyebutan zona di sini, Carbon memakai zona
            | aplikasi — yang di config/app.php memang UTC — dan pengumuman
            | yang dijadwalkan pukul 19.00 WIB tayang pukul 02.00 dini hari.
            */
            $jadwal = Carbon::parse($data['scheduled_at'], Waktu::zona())->utc();

            // Satu menit ke belakang ditoleransi: waktu tempuh mengisi form
            // tidak boleh berubah jadi galat pada menit yang sedang berjalan.
            if ($jadwal->lt(now()->subMinute())) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'Jadwalnya sudah lewat. Pilih waktu yang akan datang.',
                ]);
            }
        }

        $gambar = $request->hasFile('image_file')
            ? $this->media->store($request->file('image_file'), 'channel/pengumuman')
            : null;

        $pengumuman = ChannelAnnouncement::create([
            'body'         => $data['body'],
            'image'        => $gambar,
            'buttons'      => $tombol === [] ? null : $tombol,
            'scheduled_at' => $jadwal,
            'status'       => ChannelAnnouncement::STATUS_SCHEDULED,
            'created_by'   => $request->user()?->id,
        ]);

        app(ActivityLogger::class)->log('pengumuman', 'channel', null, [
            'id'      => $pengumuman->id,
            'jadwal'  => $jadwal?->toIso8601String(),
            'gambar'  => $gambar !== null,
            'tombol'  => count($tombol),
        ]);

        // Terjadwal berhenti di sini. `channel:announce-due` yang memungutnya
        // saat waktunya tiba — lihat routes/console.php.
        if ($jadwal !== null) {
            return back()->with('status',
                'Pengumuman dijadwalkan pada '.Waktu::ringkas($jadwal)
                .'. Selama belum tayang, ia masih bisa dibatalkan dari tabel di bawah.');
        }

        return $this->laporkan($this->service->kirim($pengumuman));
    }

    /**
     * Kirim ulang pengumuman yang gagal atau sudah dibatalkan.
     *
     * Yang SUDAH terkirim tidak bisa dikirim ulang lewat tombol ini. Kalau
     * memang perlu tayang dua kali, itu keputusan yang harus terlihat sebagai
     * pengumuman baru di riwayat — bukan satu baris yang diam-diam terkirim
     * dua kali dengan satu catatan waktu.
     */
    public function resend(Request $request, ChannelAnnouncement $pengumuman): RedirectResponse
    {
        if ($pengumuman->berhasil()) {
            return back()->with('error', 'Pengumuman ini sudah terkirim. Tulis pengumuman baru bila ingin menayangkannya lagi.');
        }

        app(ActivityLogger::class)->log('pengumuman-ulang', 'channel', null, [
            'id' => $pengumuman->id,
        ]);

        return $this->laporkan($this->service->kirim($pengumuman));
    }

    /** Batalkan pengumuman yang masih menunggu jadwal. */
    public function cancel(Request $request, ChannelAnnouncement $pengumuman): RedirectResponse
    {
        if (! $pengumuman->bisaDibatalkan()) {
            return back()->with('error', 'Hanya pengumuman yang masih menunggu jadwal yang bisa dibatalkan.');
        }

        $pengumuman->forceFill(['status' => ChannelAnnouncement::STATUS_CANCELLED])->save();

        app(ActivityLogger::class)->log('pengumuman-batal', 'channel', null, [
            'id' => $pengumuman->id,
        ]);

        return back()->with('status', 'Pengumuman dibatalkan. Ia tidak akan tayang.');
    }

    /**
     * Buang baris yang label atau URL-nya kosong, dan tolak URL yang tidak
     * bisa dipakai Telegram.
     *
     * Tombol tanpa URL bukan tombol; Telegram menolak seluruh pesannya dan
     * yang terbaca admin cuma "Bad Request: BUTTON_URL_INVALID" — pesan yang
     * tidak menyebut tombol nomor berapa. Karena itu diperiksa di sini,
     * sebelum satu pun panggilan API terjadi.
     *
     * @param  array<int,array{label?:string,url?:string}>  $mentah
     * @return array<int,array{label:string,url:string}>
     */
    private function tombol(array $mentah): array
    {
        $hasil = [];

        foreach (array_values($mentah) as $nomor => $satu) {

            $label = trim((string) ($satu['label'] ?? ''));

            $url = trim((string) ($satu['url'] ?? ''));

            // Baris yang benar-benar kosong adalah kolom yang tidak diisi,
            // bukan kesalahan. Dilewati diam-diam.
            if ($label === '' && $url === '') {
                continue;
            }

            if ($label === '' || $url === '') {
                throw ValidationException::withMessages([
                    'buttons' => 'Tombol '.($nomor + 1).' belum lengkap: label dan URL harus diisi keduanya.',
                ]);
            }

            // http, https, dan tg:// adalah yang diterima Telegram untuk
            // tombol URL. Tautan relatif seperti "/vip" tampak wajar saat
            // diketik tapi tidak punya arti di dalam aplikasi Telegram.
            if (! preg_match('#^(https?://|tg://)#i', $url)) {
                throw ValidationException::withMessages([
                    'buttons' => 'URL tombol '.($nomor + 1).' harus diawali https://, http://, atau tg://.',
                ]);
            }

            $hasil[] = ['label' => $label, 'url' => $url];
        }

        return $hasil;
    }

    private function laporkan(ChannelAnnouncement $pengumuman): RedirectResponse
    {
        return $pengumuman->berhasil()
            ? back()->with('status', 'Pengumuman terkirim ke channel.')
            : back()->with('error', 'Gagal mengirim pengumuman: '.$pengumuman->error);
    }
}
