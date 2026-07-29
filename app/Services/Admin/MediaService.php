<?php

namespace App\Services\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Penanganan unggahan gambar untuk panel admin.
 *
 * Berkas disimpan di disk `public`, diperkecil sesuai peruntukannya, dan
 * berkas lama dihapus saat diganti supaya storage tidak menumpuk sampah.
 */
class MediaService
{
    /** Ukuran maksimum yang diterima, dalam kilobyte. */
    public const MAX_KB = 4096;

    /** Jenis berkas yang diizinkan. */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    /** Folder penyimpanan => preset ukuran. */
    private const PRESET_MAP = [
        'drama/poster'       => 'poster',
        'drama/cover'        => 'cover',
        'episode/thumbnail'  => 'thumbnail',
        'banner'             => 'banner',
        'settings'           => 'logo',
    ];

    public function __construct(
        protected ImageProcessor $processor
    ) {
    }

    /**
     * Menyimpan berkas dan mengembalikan path relatifnya.
     *
     * @param  string|null  $previous  Path lama yang akan dihapus bila ada.
     */
    public function store(UploadedFile $file, string $folder, ?string $previous = null): string
    {
        $extension = Str::lower($file->getClientOriginalExtension() ?: 'jpg');
        $name = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($folder, $name, 'public');

        // Perkecil di tempat setelah tersimpan.
        $this->processor->optimise(
            Storage::disk('public')->path($path),
            self::PRESET_MAP[$folder] ?? 'default'
        );

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

    /** Keterangan batasan untuk ditampilkan di form. */
    public static function hint(string $preset = 'default'): string
    {
        [$w, $h] = ImageProcessor::PRESETS[$preset] ?? ImageProcessor::PRESETS['default'];

        return sprintf(
            'JPG, PNG, atau WebP. Maksimal %d MB. Otomatis diperkecil ke maksimal %d×%d piksel.',
            self::MAX_KB / 1024,
            $w,
            $h
        );
    }
}
