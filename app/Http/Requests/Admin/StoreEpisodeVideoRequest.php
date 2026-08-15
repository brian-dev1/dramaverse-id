<?php

namespace App\Http\Requests\Admin;

use App\Enums\StorageCollection;
use App\Rules\UsableStorageProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi unggahan video episode.
 *
 * Batas ukuran, ekstensi, dan direktori TIDAK ditulis ulang di sini — semuanya
 * dibaca dari `StorageCollection::EPISODE`, sumber yang sama dengan yang
 * dipakai Storage Engine saat menolak berkas. Menuliskannya terpisah berarti
 * form menjanjikan sesuatu yang engine tolak, atau sebaliknya.
 */
class StoreEpisodeVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route sudah dilindungi middleware `permission:episode.manage`.
        return true;
    }

    public function rules(): array
    {
        $collection = StorageCollection::EPISODE;

        return [
            'episode_id' => ['required', 'integer', Rule::exists('episodes', 'id')],

            // Judul opsional. Bila diisi, judul episode diperbarui sekalian —
            // supaya admin tidak perlu membuka form episode hanya untuk itu.
            'title' => ['nullable', 'string', 'max:255'],

            'storage_mode' => ['required', Rule::in(['auto', 'manual'])],

            'storage_provider_id' => [
                'nullable',
                'required_if:storage_mode,manual',
                'integer',
                new UsableStorageProvider(requireTested: true),
            ],

            'video' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $this->allowedMimetypes()),
                'extensions:'.implode(',', $collection->extensions()),
                'max:'.$this->effectiveMaxKb(),
            ],
        ];
    }

    /**
     * Batas ukuran efektif dalam kilobyte.
     *
     * Yang berlaku adalah yang TERKECIL antara batas aplikasi dan batas PHP.
     * Menampilkan batas aplikasi saja akan menyesatkan: berkas yang melewati
     * `upload_max_filesize` ditolak web server sebelum PHP berjalan, sehingga
     * pesan yang muncul bukan pesan kita melainkan galat 413 tanpa penjelasan.
     */
    public function effectiveMaxKb(): int
    {
        $batas = [StorageCollection::EPISODE->maxKb() ?? PHP_INT_MAX];

        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $nilai = self::iniToKb((string) ini_get($ini));

            if ($nilai !== null) {
                $batas[] = $nilai;
            }
        }

        return max(1, (int) min($batas));
    }

    /**
     * Ubah nilai php.ini seperti "8M", "1G", atau "512K" menjadi kilobyte.
     *
     * Mengembalikan null bila tidak dibatasi (nilai 0 atau kosong).
     */
    public static function iniToKb(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || $value === '0' || $value === '-1') {
            return null;
        }

        $unit = strtoupper(substr($value, -1));

        $angka = (float) $value;

        return match ($unit) {
            'G'     => (int) ($angka * 1024 * 1024),
            'M'     => (int) ($angka * 1024),
            'K'     => (int) $angka,
            default => (int) ($angka / 1024), // byte
        };
    }

    /**
     * Mimetype yang diterima.
     *
     * Memakai `mimetypes` (bukan `mimes`), yang memeriksa isi berkas alih-alih
     * ekstensinya. Keduanya dipakai bersama: `mimetypes` menahan berkas yang
     * dinamai .mp4 tapi isinya bukan video, `extensions` menahan ekstensi yang
     * tidak dikenali koleksi.
     */
    protected function allowedMimetypes(): array
    {
        return [
            'video/mp4',
            'video/x-matroska',
            'video/webm',
            'video/quicktime',
            'video/x-m4v',
        ];
    }

    /**
     * Mode AUTO berarti provider tidak disebutkan sama sekali.
     *
     * Tanpa pembersihan ini, nilai provider yang tertinggal di form (misalnya
     * admin memilih manual, memilih provider, lalu berpindah ke auto) akan
     * ikut terkirim dan membingungkan: modenya auto tapi ada provider terpilih.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('storage_mode') === 'auto') {
            $this->merge(['storage_provider_id' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'video.required'   => 'Berkas video wajib dipilih.',
            'video.file'       => 'Unggahan tidak sampai dengan utuh. Coba lagi.',
            'video.mimetypes'  => 'Berkas itu bukan video. Yang diterima: MP4, MKV, WebM, MOV, M4V.',
            'video.extensions' => 'Ekstensi berkas tidak diterima. Yang diizinkan: '
                                  .implode(', ', StorageCollection::EPISODE->extensions()).'.',
            'video.max'        => 'Berkas melewati batas '
                                  .number_format($this->effectiveMaxKb() / 1024, 0)
                                  .' MB yang berlaku di server ini.',

            'episode_id.required' => 'Pilih part tujuan lebih dulu.',
            'episode_id.exists'   => 'Part itu tidak ditemukan. Muat ulang halaman.',

            'storage_provider_id.required_if' =>
                'Mode Manual memerlukan storage provider yang dipilih.',
        ];
    }

    public function attributes(): array
    {
        return [
            'video'               => 'berkas video',
            'episode_id'          => 'episode',
            'storage_provider_id' => 'storage provider',
            'storage_mode'        => 'mode penyimpanan',
        ];
    }
}
