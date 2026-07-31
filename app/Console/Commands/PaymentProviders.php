<?php

namespace App\Console\Commands;

use App\Models\PaymentProvider;
use Illuminate\Console\Command;

/**
 * Daftar seluruh provider pembayaran beserta URL callback-nya.
 *
 * ## Kenapa perintah ini ada
 *
 * `404 {"message":"Provider tidak dikenal."}` pada endpoint callback berarti
 * slug di URL tidak cocok dengan slug mana pun di basis data. Itu keterangan
 * yang benar, tetapi tidak menyebutkan slug yang BENAR — dan mencarinya
 * berarti membuka panel admin, yang tidak selalu ada di depan orang yang
 * sedang menempelkan URL ke dashboard gateway.
 *
 * Perintah ini menampilkan slug apa adanya, lengkap dengan URL yang harus
 * disalin. Termasuk provider yang nonaktif dan yang kredensialnya belum
 * lengkap — justru itu yang paling sering jadi penyebabnya, dan
 * menyembunyikannya membuat orang menyimpulkan providernya belum dibuat
 * padahal sudah.
 */
class PaymentProviders extends Command
{
    protected $signature = 'payment:providers';

    protected $description = 'Tampilkan provider pembayaran beserta URL callback-nya';

    public function handle(): int
    {
        $providers = PaymentProvider::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($providers->isEmpty()) {

            $this->components->error('Belum ada satu pun provider pembayaran.');

            $this->newLine();
            $this->components->bulletList([
                'Buka /admin/payment/provider di panel admin.',
                'Tambah provider, pilih driver Trakteer.',
                'Isi webhook_token dan page_url, lalu aktifkan.',
                'Jalankan perintah ini lagi untuk melihat URL callback-nya.',
            ]);

            return self::FAILURE;
        }

        foreach ($providers as $p) {

            $this->newLine();

            $this->components->twoColumnDetail(
                '<options=bold>'.$p->name.'</>',
                $p->isUsable() ? '<fg=green>siap dipakai</>' : '<fg=red>belum siap</>'
            );

            $this->components->twoColumnDetail('Slug', '<options=bold>'.$p->slug.'</>');
            $this->components->twoColumnDetail('Driver', $p->driver->value);
            $this->components->twoColumnDetail(
                'Keadaan',
                ($p->is_active ? 'aktif' : 'nonaktif')
                    .' · '.$p->mode
                    .($p->is_default ? ' · default' : '')
                    .($p->trashed() ? ' · TERHAPUS' : '')
            );

            $this->components->twoColumnDetail(
                'URL callback',
                url('/payment/callback/'.$p->slug)
            );

            // Alasan tidak bisa dipakai disebutkan apa adanya. Tanpa ini,
            // "belum siap" hanya memindahkan pertanyaan.
            if ($alasan = $p->blocker()) {
                $this->line('        <fg=yellow>'.$alasan.'</>');
            }
        }

        $this->newLine();

        $siap = $providers->filter(fn (PaymentProvider $p) => $p->isUsable());

        if ($siap->isEmpty()) {
            $this->components->warn(
                'Tidak ada provider yang siap. Callback dari gateway akan ditolak '
                .'selama ini belum dibereskan.'
            );
        } else {
            $this->components->info(
                'Salin URL callback provider yang siap ke dashboard gateway-nya.'
            );
        }

        return self::SUCCESS;
    }
}
