<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistem Affiliate / Referral.
 *
 * Tiga tabel + dua kolom di users. Prinsipnya: ikatan referral hanya boleh
 * ditulis SEKALI (referred_by_id), dan komisi hanya boleh lahir dari invoice
 * yang benar-benar lunas — dijamin unique index pada invoice_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 32)->nullable()->unique()->after('is_active');
            }
            if (! Schema::hasColumn('users', 'referred_by_id')) {
                $table->foreignId('referred_by_id')->nullable()->after('referral_code')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'referred_at')) {
                $table->timestamp('referred_at')->nullable()->after('referred_by_id');
            }
        });

        // Tingkatan komisi. Bisa diubah admin lewat panel.
        Schema::create('referral_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->decimal('rate', 5, 2);              // persen, mis. 20.00
            $table->unsignedInteger('min_referrals');   // ambang naik level
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Satu baris = satu komisi dari satu invoice lunas.
        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();

            // Unique: satu invoice tidak akan pernah menghasilkan dua komisi,
            // walau callback pembayaran datang berkali-kali.
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();

            $table->decimal('base_amount', 14, 2);   // dasar perhitungan (subtotal invoice)
            $table->decimal('rate', 5, 2);           // persen saat komisi dibuat
            $table->decimal('amount', 14, 2);        // nilai komisi rupiah
            $table->unsignedTinyInteger('level')->default(1);

            // pending  → masa tahan (hold) belum lewat
            // available→ boleh ditarik
            // paid     → sudah ikut dalam penarikan yang dibayar
            // void     → dibatalkan admin (refund/kecurangan)
            $table->string('status', 16)->default('available');
            $table->timestamp('available_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('referral_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->string('method', 32);            // dana / ovo / gopay / shopeepay
            $table->string('account_number', 64);
            $table->string('account_name', 128);
            $table->string('status', 16)->default('pending'); // pending|approved|rejected|paid
            $table->string('note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Jejak klik/kunjungan lewat tautan referral. Dipakai admin untuk
        // membandingkan "klik" vs "daftar" vs "beli" — pola curang terlihat
        // dari perbandingan itu.
        Schema::create('referral_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['referrer_id', 'created_at']);
            $table->index('fingerprint');
        });

        // Tingkatan bawaan — sama persis dengan rancangan di bot.
        $now = now();
        \DB::table('referral_tiers')->insert([
            ['level' => 1, 'rate' => 20, 'min_referrals' => 0,     'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 2, 'rate' => 30, 'min_referrals' => 1000,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 3, 'rate' => 35, 'min_referrals' => 3000,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 4, 'rate' => 40, 'min_referrals' => 5000,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => 5, 'rate' => 50, 'min_referrals' => 10000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Pengaturan bawaan.
        foreach ([
            'referral_enabled'      => '1',
            'referral_min_withdraw' => '30000',
            'referral_fee_percent'  => '0',
            'referral_cookie_days'  => '30',
            'referral_hold_days'    => '0',
            'referral_base'         => 'subtotal',   // subtotal | total
            'referral_ewallets'     => 'DANA,OVO,GoPay,ShopeePay',
        ] as $key => $value) {
            \DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'group' => 'referral', 'type' => 'text', 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_visits');
        Schema::dropIfExists('referral_withdrawals');
        Schema::dropIfExists('referral_commissions');
        Schema::dropIfExists('referral_tiers');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by_id')) {
                $table->dropConstrainedForeignId('referred_by_id');
            }
            if (Schema::hasColumn('users', 'referred_at')) {
                $table->dropColumn('referred_at');
            }
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropColumn('referral_code');
            }
        });

        \DB::table('settings')->where('group', 'referral')->delete();
    }
};
