<?php

namespace App\Repositories;

use App\Models\Episode;
use App\Models\User;
use App\Repositories\Contracts\EpisodeAccessRepositoryInterface;

/**
 * Satu-satunya tempat yang menjawab "boleh menonton episode ini atau tidak".
 *
 * Dipakai pemutar website DAN bot Telegram (Sprint 8.5) lewat
 * `EpisodeAccessService`. Menyalin aturannya ke salah satunya akan melahirkan
 * dua definisi yang harus dijaga tetap sama.
 *
 * ## Bug yang diperbaiki di Phase 10
 *
 * Versi sebelumnya memeriksa `$episode->access_type === 'free'`. Kolom
 * `access_type` **tidak pernah ada** — yang ada `is_vip`. Eloquent
 * mengembalikan null untuk atribut yang tidak ada, tanpa galat apa pun,
 * sehingga cabang itu tidak pernah bernilai benar.
 *
 * Digabung dengan `$user->is_premium` yang kolomnya juga tidak ada,
 * hasilnya: **method ini selalu mengembalikan false.** Tidak ada satu pun
 * episode yang bisa ditonton siapa pun, termasuk episode gratis. Kegagalannya
 * diam sepenuhnya — tidak ada galat, tidak ada log, hanya pemutar yang
 * menolak tanpa alasan.
 */
class EpisodeAccessRepository implements EpisodeAccessRepositoryInterface
{
    public function canWatch(
        ?User $user,
        Episode $episode
    ): bool {

        // Episode gratis terbuka untuk siapa pun, termasuk yang belum masuk.
        if (! $episode->is_vip) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        // Admin selalu bisa membuka apa pun — ia harus bisa memeriksa isi
        // berbayar tanpa membeli langganannya sendiri.
        if ($user->is_admin) {
            return true;
        }

        if (! $user->is_premium) {
            return false;
        }

        // Tanpa tanggal berakhir berarti berlaku selamanya. Dengan tanggal,
        // yang sudah lewat tidak lagi memberi akses — pemeriksaan ini tetap
        // ada meski scheduler juga mengedaluwarsakan langganan, karena
        // scheduler berjalan berkala dan bisa terlambat beberapa menit.
        return $user->premium_expired_at === null
            || now()->lt($user->premium_expired_at);
    }
}
