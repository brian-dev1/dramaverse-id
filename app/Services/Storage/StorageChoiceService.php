<?php

namespace App\Services\Storage;

use App\Models\StorageProvider;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;

/**
 * Pilihan penyimpanan untuk form unggah.
 *
 * Dua pertanyaan yang ditanyakan setiap halaman unggah, dan yang jawabannya
 * harus sama di semua halaman:
 *
 * 1. Provider mana yang boleh dipilih di mode Manual?
 * 2. Ke mana mode Auto akan mengirim berkasnya?
 *
 * Sampai Sprint 7.7, jawabannya ditulis sebagai dua method `protected` di
 * dalam `EpisodeVideoController`. Begitu halaman unggah kedua muncul —
 * Batch Upload — salinan kedua akan lahir, dan dua salinan syarat "provider
 * yang boleh dipilih" adalah cara paling mudah mendapatkan satu halaman yang
 * menawarkan provider yang halaman lain tolak.
 *
 * Syaratnya sengaja sama persis dengan yang ditegakkan aturan validasi
 * `UsableStorageProvider`: aktif, adapternya terpasang, field wajibnya
 * lengkap, tanpa nilai contoh, dan sudah lolos Test Connection. Daftar dan
 * validasi membaca syarat yang sama, jadi tidak ada pilihan yang muncul di
 * dropdown lalu ditolak begitu dikirim.
 */
class StorageChoiceService
{
    public function __construct(
        protected StorageEngineInterface $storage,
    ) {
    }

    /**
     * Provider yang boleh dipilih di mode Manual.
     *
     * @return array<int, string>  id => label
     */
    public function manualOptions(): array
    {
        return StorageProvider::query()
            ->active()
            ->byPriority()
            ->get()
            ->filter(fn (StorageProvider $p) => $p->isUsable() && $p->last_test_status === 'ok')
            ->mapWithKeys(fn (StorageProvider $p) => [
                $p->id => sprintf(
                    '%s — %s%s',
                    $p->name,
                    $p->driver->label(),
                    $p->is_default ? ' (default)' : ''
                ),
            ])
            ->all();
    }

    /**
     * Keterangan tujuan mode AUTO, atau null bila belum ada.
     *
     * Ditampilkan di form supaya "Auto" tidak terasa seperti kotak hitam.
     * Admin yang mengunggah berkas 3 GB berhak tahu ke mana berkasnya pergi
     * sebelum menekan tombol.
     */
    public function autoTarget(): ?string
    {
        try {
            $provider = $this->storage->resolveProvider();
        } catch (StorageEngineException) {
            return null;
        }

        return sprintf('%s — %s', $provider->name, $provider->driver->label());
    }
}
