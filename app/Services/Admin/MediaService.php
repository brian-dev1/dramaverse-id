<?php

namespace App\Services\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penanganan unggahan gambar untuk panel admin.
 *
 * Disimpan di disk `public` agar dapat diakses lewat symlink storage.
 * Berkas lama dihapus saat diganti supaya tidak menumpuk.
 */
class MediaService
{
    /** Ukuran maksimum yang diterima, dalam kilobyte. */
    public const MAX_KB = 4096;

    /** Jenis berkas yang diizinkan. */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Menyimpan berkas dan mengembalikan path relatifnya.
     *
     * @param  string|null  $previous  Path lama yang akan dihapus bila ada.
     */
    public function store(UploadedFile $file, string $folder, ?string $previous = null): string
    {
        $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($folder, $name, 'public');

        if ($previous) {
            $this->delete($previous);
        }

        return $path;
    }

    /** Menghapus berkas bila masih ada. */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /** Aturan validasi seragam untuk semua unggahan gambar. */
    public static function rules(bool $required = false): array
    {
        return array_filter([
            $required ? 'required' : 'nullable',
            'image',
            'mimes:'.implode(',', self::MIMES),
            'max:'.self::MAX_KB,
        ]);
    }
}
