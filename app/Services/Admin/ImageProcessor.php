<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;

/**
 * Memperkecil dan mengompres gambar memakai ekstensi GD bawaan PHP.
 *
 * Tidak memakai paket pihak ketiga. Bila GD tidak tersedia atau berkas
 * gagal diproses, gambar asli dibiarkan apa adanya — unggahan tetap
 * berhasil, hanya tidak dioptimalkan.
 */
class ImageProcessor
{
    /** Batas dimensi per jenis: [lebar maks, tinggi maks]. */
    public const PRESETS = [
        'poster'    => [600, 900],
        'cover'     => [1600, 900],
        'thumbnail' => [640, 360],
        'banner'    => [1600, 900],
        'logo'      => [512, 512],
        'default'   => [1600, 1600],
    ];

    private const JPEG_QUALITY = 82;
    private const WEBP_QUALITY = 80;
    private const PNG_LEVEL    = 7;

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * Memproses berkas di tempat. Mengembalikan true bila berhasil diubah.
     */
    public function optimise(string $absolutePath, string $preset = 'default'): bool
    {
        if (! $this->available() || ! is_file($absolutePath)) {
            return false;
        }

        [$maxW, $maxH] = self::PRESETS[$preset] ?? self::PRESETS['default'];

        try {
            $info = @getimagesize($absolutePath);

            if ($info === false) {
                return false;
            }

            [$width, $height] = $info;
            $mime = $info['mime'] ?? '';

            $source = $this->read($absolutePath, $mime);

            if (! $source) {
                return false;
            }

            $source = $this->applyExifOrientation($source, $absolutePath, $mime);

            $width  = imagesx($source);
            $height = imagesy($source);

            $scale = min($maxW / $width, $maxH / $height, 1);

            // Sudah cukup kecil: cukup tulis ulang untuk mengompres.
            $newW = max(1, (int) round($width * $scale));
            $newH = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($newW, $newH);

            // Pertahankan transparansi PNG dan WebP.
            if (in_array($mime, ['image/png', 'image/webp'], true)) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
            }

            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

            $written = $this->write($canvas, $absolutePath, $mime);

            imagedestroy($source);
            imagedestroy($canvas);

            return $written;
        } catch (\Throwable $e) {
            Log::warning('Gagal mengoptimalkan gambar', [
                'berkas' => basename($absolutePath),
                'alasan' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function read(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
    }

    private function write(\GdImage $image, string $path, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, self::JPEG_QUALITY),
            'image/png'  => imagepng($image, $path, self::PNG_LEVEL),
            'image/webp' => imagewebp($image, $path, self::WEBP_QUALITY),
            default      => false,
        };
    }

    /**
     * Memutar gambar sesuai data EXIF.
     *
     * Foto dari ponsel sering tersimpan miring dengan penanda orientasi;
     * tanpa koreksi ini, poster yang diunggah bisa tampil terbalik.
     */
    private function applyExifOrientation(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3       => imagerotate($image, 180, 0) ?: $image,
            6       => imagerotate($image, -90, 0) ?: $image,
            8       => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
