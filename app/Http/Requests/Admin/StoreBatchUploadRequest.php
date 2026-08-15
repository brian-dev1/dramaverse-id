<?php

namespace App\Http\Requests\Admin;

use App\Enums\DramaAssetType;
use App\Enums\StorageCollection;
use App\Rules\UsableStorageProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi SATU berkas dalam sebuah batch.
 *
 * Perhatikan: satu berkas, bukan satu batch. Peramban mengirim tiap berkas
 * sebagai permintaan tersendiri, dan itu bukan kemalasan — itulah cara dua
 * permintaan spesifikasi dipenuhi sekaligus:
 *
 * - "Tampilkan progress upload per file" memerlukan
 *   `XMLHttpRequest.upload.onprogress` per berkas. Satu permintaan berisi dua
 *   puluh berkas hanya punya satu progress, dan angkanya melompat dari 40%
 *   langsung ke 100% tanpa ada yang tahu berkas mana yang sedang jalan.
 *
 * - "Jika satu file gagal, file lainnya tetap diproses" jauh lebih mudah
 *   dijamin bila kegagalan satu berkas memang hanya satu permintaan yang
 *   gagal. Dalam satu permintaan besar, validasi Laravel menolak SELURUH
 *   payload begitu satu elemen `files.*` tidak lolos.
 *
 * Ada harga yang harus disebut: `post_max_size` tetap berlaku per permintaan,
 * dan dua puluh permintaan berurutan lebih lambat daripada satu. Untuk berkas
 * yang ukurannya memang besar, yang menentukan adalah waktu pengiriman, bukan
 * jumlah permintaannya.
 *
 * Aturan per jenis tidak ditulis ulang di sini. Video membaca
 * `StorageCollection::EPISODE` dan aset membaca `DramaAssetType`, sumber yang
 * sama persis dengan yang dipakai `StoreEpisodeVideoRequest` dan
 * `StoreDramaAssetRequest`. Kalau ditulis ulang, batch dan unggahan satuan
 * akan menerima berkas yang berbeda tanpa ada yang memutuskan begitu.
 */
class StoreBatchUploadRequest extends FormRequest
{
    public const KIND_VIDEO = 'video';

    public const KIND_ASSET = 'asset';

    public function authorize(): bool
    {
        // Route sudah dilindungi middleware permission.
        return true;
    }

    public function kind(): string
    {
        return $this->input('kind') === self::KIND_ASSET
            ? self::KIND_ASSET
            : self::KIND_VIDEO;
    }

    public function assetType(): ?DramaAssetType
    {
        return DramaAssetType::tryFrom((string) $this->input('asset_type'));
    }

    public function rules(): array
    {
        $rules = [
            'kind' => ['required', Rule::in([self::KIND_VIDEO, self::KIND_ASSET])],

            // Batch yang sudah berjalan. Kosong berarti berkas pertama, dan
            // controller yang membuatkan nilainya.
            'batch' => ['nullable', 'uuid'],

            'storage_mode' => ['required', Rule::in(['auto', 'manual'])],

            'storage_provider_id' => [
                'nullable',
                'required_if:storage_mode,manual',
                'integer',
                new UsableStorageProvider(requireTested: true),
            ],
        ];

        return $rules + ($this->kind() === self::KIND_ASSET
            ? $this->assetRules()
            : $this->videoRules());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function videoRules(): array
    {
        return [
            'episode_id' => ['required', 'integer', Rule::exists('episodes', 'id')],

            'file' => [
                'required',
                'file',
                'mimetypes:video/mp4,video/x-matroska,video/webm,video/quicktime,video/x-m4v',
                'extensions:'.implode(',', StorageCollection::EPISODE->extensions()),
                'max:'.$this->effectiveMaxKb(),
            ],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function assetRules(): array
    {
        $type = $this->assetType();

        $rules = [
            'drama_id'   => ['required', 'integer', Rule::exists('dramas', 'id')],
            'asset_type' => ['required', Rule::in(DramaAssetType::values())],
        ];

        // Jenis tidak dikenali: berhenti di situ. Aturan berkas di bawah
        // bergantung padanya, dan nilai bawaan hanya menghasilkan pesan galat
        // yang menyesatkan.
        if ($type === null) {
            $rules['file'] = ['required', 'file'];

            return $rules;
        }

        $rules['file'] = [
            'required',
            'file',
            'mimetypes:'.implode(',', $type->mimetypes()),
            'extensions:'.implode(',', $type->extensions()),
            'max:'.$this->effectiveMaxKb(),
        ];

        return $rules;
    }

    /**
     * Batas ukuran efektif dalam kilobyte, sesuai jenis yang sedang diunggah.
     *
     * Yang berlaku adalah yang TERKECIL antara batas aplikasi dan batas PHP.
     * Perhitungan php.ini-nya dipinjam dari `StoreEpisodeVideoRequest` — satu
     * salinan, bukan dua yang bisa berbeda.
     */
    public function effectiveMaxKb(): int
    {
        $aplikasi = $this->kind() === self::KIND_ASSET
            ? ($this->assetType()?->maxKb() ?? 4096)
            : (StorageCollection::EPISODE->maxKb() ?? PHP_INT_MAX);

        $batas = [$aplikasi];

        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $nilai = StoreEpisodeVideoRequest::iniToKb((string) ini_get($ini));

            if ($nilai !== null) {
                $batas[] = $nilai;
            }
        }

        return max(1, (int) min($batas));
    }

    /**
     * Mode AUTO berarti provider tidak disebutkan sama sekali.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('storage_mode') === 'auto') {
            $this->merge(['storage_provider_id' => null]);
        }

        // Batch kosong dari peramban datang sebagai string kosong, bukan null,
        // dan `uuid` menolak string kosong. Dinormalkan di sini supaya berkas
        // pertama tidak gagal validasi hanya karena belum punya batch.
        if ($this->input('batch') === '') {
            $this->merge(['batch' => null]);
        }
    }

    public function messages(): array
    {
        $type = $this->assetType();

        return [
            'file.required'   => 'Berkas tidak sampai ke server.',
            'file.file'       => 'Unggahan tidak sampai dengan utuh. Coba lagi.',
            'file.mimetypes'  => $this->kind() === self::KIND_ASSET
                ? 'Isi berkasnya tidak cocok dengan jenis aset yang dipilih.'
                : 'Berkas itu bukan video. Yang diterima: MP4, MKV, WebM, MOV, M4V.',
            'file.extensions' => 'Ekstensi tidak diterima. Yang diizinkan: '
                                 .implode(', ', $this->kind() === self::KIND_ASSET
                                     ? ($type?->extensions() ?? [])
                                     : StorageCollection::EPISODE->extensions()).'.',
            'file.max'        => 'Berkas melewati batas '
                                 .number_format($this->effectiveMaxKb() / 1024, 1)
                                 .' MB yang berlaku di server ini.',

            'episode_id.required' => 'Berkas ini belum dipetakan ke part mana pun.',
            'episode_id.exists'   => 'Part itu tidak ditemukan. Muat ulang halaman.',

            'drama_id.required' => 'Pilih drama tujuan lebih dulu.',
            'drama_id.exists'   => 'Drama itu tidak ditemukan. Muat ulang halaman.',

            'asset_type.required' => 'Jenis aset belum dipilih.',
            'asset_type.in'       => 'Jenis aset itu tidak dikenali.',

            'batch.uuid' => 'Penanda batch tidak berbentuk uuid.',

            'storage_provider_id.required_if' =>
                'Mode Manual memerlukan storage provider yang dipilih.',
        ];
    }

    public function attributes(): array
    {
        return [
            'file'                => 'berkas',
            'episode_id'          => 'episode',
            'drama_id'            => 'drama',
            'asset_type'          => 'jenis aset',
            'storage_provider_id' => 'storage provider',
            'storage_mode'        => 'mode penyimpanan',
        ];
    }
}
