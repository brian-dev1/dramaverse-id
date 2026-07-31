<?php

namespace Database\Seeders;

use App\Enums\PaymentDriver;
use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

/**
 * Memasang provider transfer manual sebagai bawaan.
 *
 * Hanya satu, dan sengaja hanya itu: `manual` adalah satu-satunya driver yang
 * bisa dipakai tanpa akun pihak ketiga. Memasang kerangka Midtrans dan Xendit
 * yang belum selesai hanya akan mengisi panel dengan baris yang menolak
 * diaktifkan.
 *
 * `firstOrCreate` pada slug, bukan `updateOrCreate`: nomor rekening yang sudah
 * diisi admin tidak boleh dikembalikan jadi kosong hanya karena seeder
 * dijalankan lagi setelah deploy.
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $manual = PaymentProvider::firstOrCreate(
            ['slug' => 'transfer-manual'],
            [
                'name'        => 'Transfer Bank',
                'driver'      => PaymentDriver::MANUAL->value,
                'mode'        => 'live',
                'sort_order'  => 1,
                // Nonaktif: kredensialnya (nomor rekening) belum diisi, dan
                // provider aktif tanpa kredensial gagal di tengah checkout.
                'is_active'   => false,
                'is_default'  => false,
                'instruction' => "Transfer sesuai nominal tagihan, lalu kirim bukti "
                    ."transfer ke admin lewat bot Telegram.\n\n"
                    ."Membership aktif setelah pembayaran diverifikasi.",
            ]
        );

        $this->command?->info(
            $manual->wasRecentlyCreated
                ? 'Provider "Transfer Bank" dibuat. Isi nomor rekeningnya di '
                    .'/admin/payment/provider, lalu aktifkan.'
                : 'Provider "Transfer Bank" sudah ada, tidak diubah.'
        );
    }
}
