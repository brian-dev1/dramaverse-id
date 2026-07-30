<?php

namespace App\Http\Requests\Admin;

use App\Enums\DramaAssetType;
use App\Rules\UsableStorageProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi unggahan aset drama.
 *
 * Aturan per jenis aset dibaca dari `DramaAssetType`, sumber yang sama dengan
 * yang dipakai `DramaAssetService::assertAllowed()`. Menuliskannya terpisah
 * berarti form menjanjikan sesuatu yang service tolak, atau sebaliknya.
 */
class StoreDramaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route sudah dilindungi middleware `permission:drama.manage`.
        return true;
    }

    /**
     * Jenis aset yang sedang diunggah, atau null bila nilainya tidak dikenali.
     */
    public function assetType(): ?DramaAssetType
    {
        return DramaAssetType::tryFrom((string) $this->input('asset_type'));
    }

    public function rules(): array
    {
        $type = $this->assetType();

        $rules = [
            'asset_type' => ['required', Rule::in(DramaAssetType::values())],

            'storage_mode' => ['required', Rule::in(['auto', 'manual'])],

            'storage_provider_id' => [
                'nullable',
                'required_if:storage_mode,manual',
                'integer',
                new UsableStorageProvider(requireTested: true),
            ],
        ];

        // Jenis tidak dikenali: hentikan di situ. Aturan berkas di bawah
        // bergantung padanya, dan memakai nilai bawaan hanya akan menghasilkan
        // pesan galat yang menyesatkan.
        if ($type === null) {
            $rules['files'] = ['required', 'array', 'min:1'];

            return $rules;
        }

        // Selalu array, termasuk untuk jenis tunggal. Satu bentuk payload
        // untuk semua jenis berarti satu jalur kode di sisi JavaScript maupun
        // controller — galeri hanya berbeda pada jumlah yang diizinkan.
        $rules['files'] = [
            'required',
            'array',
            'min:1',
            $type->allowsMultiple() ? 'max:20' : 'max:1',
        ];

        $rules['files.*'] = [
            'required',
            'file',
            'mimetypes:'.implode(',', $type->mimetypes()),
            'extensions:'.implode(',', $type->extensions()),
            'max:'.$this->effectiveMaxKb($type),
        ];

        return $rules;
    }

    /**
     * Batas ukuran efektif dalam kilobyte.
     *
     * Yang berlaku adalah yang TERKECIL antara batas jenis aset dan batas PHP.
     * Menampilkan batas aplikasi saja menyesatkan: berkas yang melewati
     * `upload_max_filesize` ditolak web server sebelum PHP berjalan, sehingga
     * yang muncul bukan pesan kita.
     */
    public function effectiveMaxKb(?DramaAssetType $type = null): int
    {
        $type ??= $this->assetType();

        $batas = [$type?->maxKb() ?? 4096];

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
    }

    public function messages(): array
    {
        $type = $this->assetType();

        $daftarExt = $type ? implode(', ', $type->extensions()) : '';

        return [
            'files.required' => 'Pilih berkas lebih dulu.',
            'files.max'      => $type?->allowsMultiple()
                ? 'Maksimal 20 berkas sekali unggah.'
                : 'Jenis aset ini hanya menerima satu berkas.',

            'files.*.file'       => 'Unggahan tidak sampai dengan utuh. Coba lagi.',
            'files.*.mimetypes'  => $type?->isSubtitle()
                ? 'Berkas itu bukan berkas subtitle.'
                : 'Berkas itu bukan gambar. Isi berkasnya diperiksa, bukan hanya namanya.',
            'files.*.extensions' => "Ekstensi tidak diterima. Yang diizinkan: {$daftarExt}.",
            'files.*.max'        => 'Berkas melewati batas '
                                    .number_format($this->effectiveMaxKb() / 1024, 1)
                                    .' MB yang berlaku untuk jenis aset ini.',

            'asset_type.required' => 'Jenis aset tidak disebutkan.',
            'asset_type.in'       => 'Jenis aset itu tidak dikenali.',

            'storage_provider_id.required_if' =>
                'Mode Manual memerlukan storage provider yang dipilih.',
        ];
    }

    public function attributes(): array
    {
        return [
            'files'               => 'berkas',
            'files.*'             => 'berkas',
            'asset_type'          => 'jenis aset',
            'storage_provider_id' => 'storage provider',
            'storage_mode'        => 'mode penyimpanan',
        ];
    }
}
