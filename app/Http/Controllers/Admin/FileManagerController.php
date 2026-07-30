<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StoredFileSource;
use App\Http\Controllers\Controller;
use App\Models\StorageProvider;
use App\Services\Admin\ActivityLogger;
use App\Services\Storage\Exceptions\StorageEngineException;
use App\Services\Storage\FileManagerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File Manager.
 *
 * Satu daftar untuk seluruh berkas yang dikenal aplikasi, dengan rename,
 * move, delete, unduh, pratayang, dan salin URL.
 *
 * Controller ini tidak menyentuh `Storage`, tidak menyebut nama disk, dan
 * tidak tahu driver apa pun. Setiap operasi berkas diteruskan ke
 * `FileManagerService`, yang seluruhnya bekerja lewat
 * `StorageEngineInterface`.
 */
class FileManagerController extends Controller
{
    public function __construct(
        protected FileManagerService $files,
        protected ActivityLogger $activity,
    ) {
    }

    /**
     * Daftar berkas.
     */
    public function index(Request $request): View
    {
        $filters = [
            'q'        => trim((string) $request->query('q', '')),
            'source'   => (string) $request->query('source', ''),
            'provider' => (string) $request->query('provider', ''),
            'kind'     => (string) $request->query('kind', ''),
            'ext'      => (string) $request->query('ext', ''),
            'sort'     => (string) $request->query('sort', 'uploaded_at'),
            'dir'      => (string) $request->query('dir', 'desc'),
        ];

        return view('web.pages.admin.file-manager', [
            'title'      => 'File Manager',
            'files'      => $this->files->paginate($filters),
            'filters'    => $filters,
            'sources'    => StoredFileSource::options(),
            'kinds'      => $this->files->kindOptions(),
            'extensions' => $this->files->extensions(),
            'sortable'   => FileManagerService::SORTABLE,

            // Termasuk yang nonaktif: berkas lama tetap menunjuk ke provider
            // yang sudah dimatikan, dan penyaring yang tidak menyebutnya
            // membuat berkas-berkas itu tidak bisa ditemukan sama sekali.
            'providers'  => StorageProvider::query()->byPriority()->get(['id', 'name']),

            'anyFilter'  => $this->anyFilter($filters),
        ]);
    }

    /**
     * Keterangan satu berkas beserta URL-nya, sebagai JSON.
     *
     * Dipakai pratayang gambar dan tombol Salin URL. URL bertanda tangan
     * TIDAK ikut dirender di halaman daftar, dan itu disengaja: menyusunnya
     * berarti satu panggilan ke penyimpanan per baris, dan menaruhnya di HTML
     * berarti dua puluh tautan berumur pendek ikut tersimpan di riwayat
     * peramban setiap kali halaman dibuka.
     */
    public function show(string $source, int $id): JsonResponse
    {
        try {
            $file = $this->files->locate($source.':'.$id);
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 404);
        }

        $url = $this->files->shareUrl($file);

        return response()->json([
            'ok'   => true,
            'data' => [
                'ref'        => $this->files->refOf($file),
                'filename'   => $file->stored_filename,
                'original'   => $file->original_filename,
                'object_key' => $file->object_key,
                'directory'  => $file->directory,
                'mime_type'  => $file->mime_type,
                'size_human' => $file->size_for_humans,
                'provider'   => $file->provider?->name,
                'url'        => $url,

                // Dibedakan dari `url` yang kosong karena sebabnya berbeda dan
                // yang bisa dilakukan admin pun berbeda: provider tanpa
                // `public_url` yang bisa disusun tidak akan pernah punya
                // tautan, sedangkan berkas privat hanya tidak boleh punya
                // tautan permanen.
                'url_note'   => $url === null
                    ? 'Provider ini tidak bisa menyusun URL — tidak ada public_url '
                      .'yang diisi dan penyimpanannya tidak mendukung URL bertanda '
                      .'tangan. Pakai tombol Unduh.'
                    : null,
            ],
        ]);
    }

    /**
     * Unduh berkas.
     *
     * Isinya dialirkan lewat engine, bukan diarahkan ke URL penyimpanan.
     * Itu satu-satunya cara yang bekerja untuk SEMUA provider: penyimpanan
     * lokal tidak bisa membuat URL bertanda tangan, dan berkas privat memang
     * tidak boleh punya URL permanen.
     *
     * Dialirkan, bukan dibaca ke memori. Video episode berukuran gigabyte akan
     * menghabiskan `memory_limit` sebelum satu byte pun sampai ke peramban.
     */
    public function download(string $source, int $id): StreamedResponse|RedirectResponse
    {
        try {
            $file = $this->files->locate($source.':'.$id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $stream = $this->files->stream($file);
        } catch (StorageEngineException $e) {
            return back()->with('error', 'Berkas gagal dibaca: '.$e->getMessage());
        }

        if ($stream === null) {
            return back()->with(
                'error',
                'Objeknya sudah tidak ada di penyimpanan, meskipun barisnya masih '
                .'tercatat. Berkas ini kemungkinan dihapus langsung dari bucket.'
            );
        }

        // Unduh SENGAJA tidak masuk `activity_logs`.
        //
        // Kosakata kolom `action` di tabel itu terbatas pada tindakan yang
        // MENGUBAH sesuatu — dibuat, diubah, dihapus, dipulihkan, massal — dan
        // menambahkan "dilihat" ke sana akan mencampur dua jenis catatan yang
        // dibaca untuk alasan berbeda. Pembacaan berkas sudah dicatat Storage
        // Engine sebagai `storage.read.success` di log Laravel, sesuai
        // permintaan bagian Logging spesifikasi.
        $nama = $file->original_filename ?: $file->stored_filename;

        return response()->streamDownload(
            function () use ($stream) {
                // `fpassthru` mengalirkan tanpa menahan isinya di memori.
                fpassthru($stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            $nama,
            [
                'Content-Type'   => $file->mime_type ?: 'application/octet-stream',
                'Content-Length' => (string) $file->size,
            ]
        );
    }

    /**
     * Ganti nama berkas.
     */
    public function rename(Request $request, string $source, int $id): RedirectResponse
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:120']],
            ['name.required' => 'Nama baru belum diisi.']
        );

        return $this->jalankan(
            $source.':'.$id,
            fn ($file) => $this->files->rename($file, $data['name']),
            'ganti nama',
            'Nama berkas diubah.'
        );
    }

    /**
     * Pindahkan berkas ke direktori lain.
     */
    public function move(Request $request, string $source, int $id): RedirectResponse
    {
        $data = $request->validate(
            ['directory' => ['required', 'string', 'max:400']],
            ['directory.required' => 'Direktori tujuan belum diisi.']
        );

        return $this->jalankan(
            $source.':'.$id,
            fn ($file) => $this->files->move($file, $data['directory']),
            'pindah',
            'Berkas dipindahkan.'
        );
    }

    /**
     * Hapus berkas beserta barisnya.
     */
    public function destroy(string $source, int $id): RedirectResponse
    {
        return $this->jalankan(
            $source.':'.$id,
            function ($file) {
                $this->files->delete($file);

                return $file;
            },
            'hapus',
            'Berkas dihapus dari penyimpanan dan dari daftar.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Satu jalur untuk ketiga tindakan.
     *
     * Ketiganya berbeda hanya pada apa yang dijalankan dan pesan suksesnya.
     * Menulis tiga blok try/catch yang isinya sama berarti tiga tempat yang
     * harus ingat menangkap `StorageEngineException`, memisahkannya dari baris
     * yang sudah hilang, dan mencatat aktivitasnya.
     */
    protected function jalankan(
        string $ref,
        callable $tindakan,
        string $aksi,
        string $sukses
    ): RedirectResponse {

        try {
            $file = $this->files->locate($ref);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $sebelum = $file->object_key;

        try {
            $tindakan($file);
        } catch (StorageEngineException $e) {

            // Pesan asli dari engine diteruskan apa adanya. Ia menyebut
            // provider, object key, dan sebab aslinya — semuanya hilang kalau
            // diganti dengan "terjadi kesalahan".
            return back()->with('error', ucfirst($aksi).' gagal: '.$e->getMessage());
        }

        $this->activity->log('diubah', 'storage', $file, [
            'aksi'  => $aksi,
            'dari'  => $sebelum,
            'ke'    => $file->object_key,
        ]);

        return back()->with('status', $sukses);
    }

    /**
     * @param  array<string, string>  $filters
     */
    protected function anyFilter(array $filters): bool
    {
        foreach (['q', 'source', 'provider', 'kind', 'ext'] as $kunci) {
            if (($filters[$kunci] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
