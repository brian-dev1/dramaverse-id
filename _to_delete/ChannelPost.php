<?php

namespace App\Console\Commands;

use App\Models\ChannelPost as CatatanKiriman;
use App\Models\Drama;
use App\Models\User;
use App\Services\Telegram\ChannelBulkService;
use App\Services\Telegram\ChannelPostService;
use Illuminate\Console\Command;

/**
 * Kirim katalog drama ke channel Telegram dari terminal.
 *
 * Panel admin sudah punya tombolnya. Perintah ini untuk keadaan di mana
 * panel bukan alat yang tepat: menaruh seluruh katalog lama ke channel yang
 * baru dibuat, atau menjalankannya lewat cron. Keduanya memanggil
 * `ChannelBulkService` yang sama, jadi aturan pengiriman — apa yang
 * dilewati, jeda antar drama, batas jumlah — tidak mungkin berbeda antara
 * tombol dan terminal.
 *
 * Yang dilakukan perintah ini hanya MENGANTREKAN. Tanpa worker antrean yang
 * berjalan, tidak ada satu pun pesan yang sampai ke Telegram — dan itu
 * diperiksa di depan, bukan dibiarkan terlihat seperti berhasil.
 */
class ChannelPost extends Command
{
    protected $signature = 'channel:post
                            {id?* : Id drama. Kosongkan dan pakai --semua untuk seluruh katalog.}
                            {--semua : Semua drama yang belum pernah dikirim.}
                            {--ulangi : Ikutkan yang sudah pernah dikirim (menghasilkan postingan kedua).}
                            {--dry-run : Tampilkan daftarnya saja, tidak mengantrekan apa pun.}
                            {--oleh= : Id user yang dicatat sebagai pengirim di riwayat.}';

    protected $description = 'Antrekan pengiriman katalog drama ke channel Telegram';

    public function __construct(
        protected ChannelBulkService $massal,
        protected ChannelPostService $channel
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($alasan = $this->channel->penghalang()) {
            $this->error($alasan);

            return self::FAILURE;
        }

        $ids = $this->pilih();

        if ($ids === []) {
            $this->warn('Tidak ada drama yang cocok. Pakai --semua, atau sebutkan idnya.');

            return self::SUCCESS;
        }

        $ulangi = (bool) $this->option('ulangi');

        /*
        | --dry-run menampilkan daftar yang PERSIS sama dengan yang akan
        | diantrekan, memakai penyaringan yang sama. Daftar pratinjau yang
        | disusun dengan cara berbeda dari pengirimnya adalah pratinjau yang
        | suatu saat berbohong — alasan yang sama kenapa panel admin memakai
        | `susun()` milik ChannelPostService, bukan salinannya.
        */
        if ($this->option('dry-run')) {
            return $this->tampilkan($ids, $ulangi);
        }

        $hasil = $this->massal->kirim(
            $ids,
            $this->pengirim(),
            ! $ulangi
        );

        foreach ($hasil['skipped'] as $baris) {
            $this->line('  dilewati — '.$baris);
        }

        if ($hasil['queued'] === 0) {
            $this->warn('Tidak ada yang diantrekan.');

            return self::SUCCESS;
        }

        $this->info(
            $hasil['queued'].' drama diantrekan'
            .($hasil['perkiraan'] > 0 ? ', selesai dalam ~'.$hasil['perkiraan'].' menit' : '')
            .'.'
        );

        // Diingatkan setiap kali, bukan sekali di dokumentasi. Perintah yang
        // mencetak "diantrekan" pada server tanpa worker terlihat persis
        // seperti perintah yang berhasil, dan yang menyadarinya adalah orang
        // yang menunggu postingannya muncul di channel.
        $this->line('Butuh worker antrean berjalan (queue:work). Hasil tiap drama masuk ke riwayat kiriman.');

        return self::SUCCESS;
    }

    /**
     * Id drama yang diminta.
     *
     * @return array<int>
     */
    private function pilih(): array
    {
        $ids = array_map('intval', (array) $this->argument('id'));

        $ids = array_values(array_filter($ids));

        if ($ids !== []) {
            return $ids;
        }

        if (! $this->option('semua')) {
            return [];
        }

        $sudah = $this->massal->sudahDikirim();

        return Drama::query()
            ->orderBy('title')
            ->pluck('id')
            ->when(
                ! $this->option('ulangi'),
                fn ($daftar) => $daftar->reject(fn (int $id) => isset($sudah[$id]))
            )
            ->take(ChannelBulkService::LIMIT)
            ->values()
            ->all();
    }

    /**
     * User yang dicatat sebagai pengirim, atau null.
     *
     * Kolom `sent_by` di riwayat boleh kosong — kiriman otomatis pun
     * mengosongkannya. Yang tidak boleh adalah id yang menunjuk user tidak
     * ada, jadi ketikan yang salah dihentikan di sini.
     */
    private function pengirim(): ?User
    {
        $id = $this->option('oleh');

        if ($id === null || $id === '') {
            return null;
        }

        $user = User::find((int) $id);

        if ($user === null) {
            $this->warn("User #{$id} tidak ada. Riwayat dicatat tanpa pengirim.");
        }

        return $user;
    }

    /**
     * Daftar yang akan diantrekan, tanpa mengantrekan apa pun.
     *
     * @param  array<int>  $ids
     */
    private function tampilkan(array $ids, bool $ulangi): int
    {
        $sudah = $this->massal->sudahDikirim();

        $baris = [];

        foreach (Drama::query()->whereIn('id', $ids)->orderBy('title')->get() as $drama) {

            $terkirim = isset($sudah[$drama->id]);

            $jumlah = $drama->episodes()->count();

            $baris[] = [
                $drama->id,
                $drama->title,
                $jumlah,
                match (true) {
                    $jumlah === 0            => 'dilewati — belum punya part',
                    $terkirim && ! $ulangi   => 'dilewati — sudah pernah dikirim',
                    $terkirim                => 'DIKIRIM ULANG',
                    default                  => 'akan dikirim',
                },
            ];
        }

        $this->table(['Id', 'Drama', 'Part', 'Status'], $baris);

        $akan = collect($baris)->reject(fn ($b) => str_starts_with($b[3], 'dilewati'))->count();

        $this->info($akan.' drama akan diantrekan. Jalankan ulang tanpa --dry-run untuk mengirim.');

        // Menyebut kelasnya supaya jelas angka ini bukan tebakan perintah ini
        // sendiri, melainkan jeda yang benar-benar dipakai saat dispatch.
        $this->line('Jeda antar drama: '.ChannelBulkService::JEDA_DETIK.' detik.'
            .' Batas sekali jalan: '.ChannelBulkService::LIMIT.' drama.'
            .' Status riwayat memakai '.CatatanKiriman::STATUS_SENT.'/'.CatatanKiriman::STATUS_FAILED.'.');

        return self::SUCCESS;
    }
}
