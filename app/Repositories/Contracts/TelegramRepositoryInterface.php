<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Akses data untuk sisi Telegram: siapa yang bisa dikirimi, berapa
 * jumlahnya, dan apa yang dicatat saat pengiriman ditolak.
 *
 * ## Kenapa bentuknya berubah di Sprint 8.1
 *
 * Sebelumnya kontrak ini berisi sendMessage() dan sendPhoto() — repository
 * yang membuka koneksi HTTP ke Telegram. Itu salah tempat, dan berbiaya
 * nyata: ia menyusun URL, token, dan penanganan galatnya sendiri, terpisah
 * dari dua service yang melakukan hal serupa, dan ia satu-satunya yang
 * membaca token dari `services.telegram.bot_token` sementara semua kode lain
 * membaca `telegram.bot_token`.
 *
 * Pengiriman sekarang milik TelegramServiceInterface. Yang tinggal di sini
 * adalah pekerjaan yang memang milik repository: pertanyaan ke database.
 *
 * Pertanyaan itu pun sebelumnya tersebar — segmen penerima ditulis di dalam
 * Admin\TelegramController, penonaktifan pengguna yang memblokir bot ditulis
 * di dalam SendTelegramBroadcast. Keduanya menyentuh tabel yang sama dengan
 * aturan yang sama, dari dua tempat yang tidak saling tahu.
 */
interface TelegramRepositoryInterface
{
    /**
     * Segmen penerima broadcast: kunci => label yang dibaca manusia.
     *
     * @return array<string,string>
     */
    public function audiences(): array;

    /**
     * Query pengguna untuk satu segmen.
     *
     * Selalu terbatas pada pengguna non-admin yang punya telegram_id —
     * mengirim broadcast promosi ke akun admin bukan maksud siapa pun.
     */
    public function audienceQuery(string $key): Builder;

    /**
     * Penerima siap kirim untuk satu segmen: telegram_id, dikunci id user.
     *
     * Pengguna yang diblokir dikeluarkan di sini, bukan di pemanggil, supaya
     * tidak ada jalur pengiriman yang lupa memeriksanya.
     *
     * @return Collection<int,string>
     */
    public function recipients(string $key): Collection;

    /**
     * Jumlah pengguna per segmen: kunci segmen => jumlah.
     *
     * @return array<string,int>
     */
    public function counts(): array;

    /**
     * Angka ringkas untuk halaman admin: total, active, banned, today.
     *
     * @return array<string,int>
     */
    public function stats(): array;

    public function findByTelegramId(int|string $telegramId): ?User;

    /**
     * Keadaan antrean yang dipakai broadcast: koneksi, nama antrean, dan
     * jumlah pekerjaan yang masih menunggu.
     *
     * Ada di sini karena broadcast yang "tidak terjadi apa-apa" hampir selalu
     * berarti worker tidak mendengarkan antrean yang benar — bukan berarti
     * Telegram menolak. Tanpa angka ini, satu-satunya cara mengetahuinya
     * adalah masuk ke server dan membaca tabel `jobs` sendiri.
     *
     * `pending` bernilai null bila koneksi antreannya bukan `database`,
     * karena jumlahnya tidak bisa dibaca dari sini.
     *
     * @return array{connection:string, queue:string, pending:int|null, failed:int|null}
     */
    public function queueHealth(): array;

    /**
     * Tandai pengguna nonaktif karena Telegram menolak pengiriman kepadanya —
     * bot diblokir atau akunnya dihapus.
     *
     * @return int jumlah baris yang berubah
     */
    public function deactivateByTelegramId(int|string $telegramId): int;
}
