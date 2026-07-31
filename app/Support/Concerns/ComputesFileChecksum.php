<?php

namespace App\Support\Concerns;

use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Http\UploadedFile;

/**
 * SHA256 dari berkas sementara sebelum dikirim ke penyimpanan.
 *
 * Dipakai DramaAssetService dan EpisodeVideoService. Keduanya punya salinan
 * yang sama persis sejak 7.6; penyatuannya ditunda karena spesifikasi 7.6
 * melarang menyentuh modul video episode, dan dicatat sebagai utang di
 * STATUS.md. Phase 12 adalah sprint yang boleh menyentuh keduanya.
 */
trait ComputesFileChecksum
{
    /**
     * `hash_file` membaca bertahap, jadi berkas besar tidak masuk memori
     * sekaligus.
     *
     * @throws StorageEngineException
     */
    protected function checksum(UploadedFile $file): string
    {
        $hash = @hash_file('sha256', $file->getRealPath());

        if ($hash === false || $hash === null) {
            throw StorageEngineException::invalidUpload(
                'checksum tidak bisa dihitung, berkas sementaranya tidak terbaca'
            );
        }

        return $hash;
    }
}
