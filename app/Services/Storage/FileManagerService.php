<?php

namespace App\Services\Storage;

use App\Enums\DramaAssetType;
use App\Enums\StoredFileSource;
use App\Models\EpisodeVideo;
use App\Models\StorageProvider;
use App\Services\Storage\Contracts\StorageEngineInterface;
use App\Services\Storage\Exceptions\StorageEngineException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * File Manager: satu daftar untuk seluruh berkas yang dikenal aplikasi.
 *
 * Membaca dari `episode_videos` dan `drama_assets` sekaligus lewat UNION,
 * lalu menjalankan rename, move, dan delete melalui `StorageEngineInterface`.
 * Tidak ada `Storage::` di berkas ini, tidak ada nama disk, dan tidak ada
 * pengetahuan tentang driver mana pun.
 *
 * ## Kenapa UNION dan bukan dua query yang digabung di PHP
 *
 * Karena halamannya berhalaman. Mengambil seluruh baris kedua tabel,
 * menggabungkannya di memori, mengurutkannya, lalu memotong dua puluh baris
 * pertama akan berhenti bekerja begitu katalognya benar-benar terisi — dan
 * berhentinya diam-diam, sebagai halaman yang makin lama makin lambat. UNION
 * membiarkan database yang mengurutkan dan memotong, dan indeks `uploaded_at`
 * yang sudah ada di kedua tabel tetap terpakai.
 *
 * ## Yang TIDAK ditampilkan halaman ini
 *
 * Berkas yatim — objek yang ada di bucket tetapi barisnya sudah hilang dari
 * database. Halaman ini membaca database, jadi berkas yang tidak punya baris
 * memang tidak akan muncul. Itu disebutkan terus terang di halamannya, karena
 * "File Manager" mudah disangka sebagai penjelajah isi bucket.
 */
class FileManagerService
{
    /** Berapa baris per halaman. */
    public const PER_PAGE = 20;

    /**
     * Kolom yang boleh dipakai mengurutkan.
     *
     * Daftar putih, bukan penyaringan. Nilai `sort` datang dari query string
     * dan langsung masuk ke `ORDER BY`; tanpa daftar ini, kolom mana pun —
     * termasuk yang tidak ada — bisa disebut dari URL.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'uploaded_at'       => 'Tanggal unggah',
        'size'              => 'Ukuran',
        'original_filename' => 'Nama asli',
        'owner_title'       => 'Drama',
    ];

    /**
     * Provider, dibaca sekali lalu dipakai ulang selama objek ini hidup.
     *
     * Properti objek, BUKAN `static`. Halaman File Manager berumur satu
     * request, tetapi service ini bisa diambil dari container di tempat lain —
     * termasuk di dalam worker antrean yang berumur berjam-jam. Cache statis
     * di sana akan menyajikan nama provider yang sudah berubah, dan gejalanya
     * hanya muncul di worker yang lama hidup.
     *
     * @var array<int, StorageProvider>|null
     */
    protected ?array $providers = null;

    public function __construct(
        protected StorageEngineInterface $storage,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar
    |--------------------------------------------------------------------------
    */

    /**
     * Halaman berkas sesuai penyaringan.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = DB::query()->fromSub($this->union(), 'f');

        $this->applyFilters($query, $filters);

        [$kolom, $arah] = $this->sortFrom($filters);

        $paginator = $query
            ->orderBy($kolom, $arah)

            // Pemecah seri. Tanpa ini, dua berkas dengan `uploaded_at` yang
            // sama persis — yang justru biasa terjadi pada unggahan galeri —
            // bisa muncul di urutan berbeda tiap kali halaman dimuat, dan
            // satu di antaranya menghilang dari kedua halaman.
            ->orderBy('source')
            ->orderBy('source_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $paginator->through(fn ($row) => $this->present($row));

        return $paginator;
    }

    /**
     * Gabungan kedua tabel sumber.
     *
     * Urutan dan JUMLAH kolom kedua sisi harus sama persis — MySQL mencocokkan
     * kolom UNION berdasarkan posisi, bukan nama, dan ketidakcocokan di sini
     * menghasilkan data yang tertukar diam-diam, bukan galat.
     */
    protected function union(): QueryBuilder
    {
        // Tidak ada satu pun binding (`?`) di dalam kedua sisi UNION ini.
        // Itu disengaja: binding subquery dan binding query luar disusun
        // terpisah oleh Laravel, dan mencampurnya di sini adalah cara paling
        // mudah mendapatkan nilai yang tertukar antar penyaring.
        $videos = DB::table('episode_videos as ev')
            ->leftJoin('episodes as e', 'e.id', '=', 'ev.episode_id')
            ->leftJoin('dramas as d', 'd.id', '=', 'e.drama_id')
            ->select([
                DB::raw("'episode_video' as source"),
                'ev.id as source_id',
                'ev.storage_provider_id as storage_provider_id',
                'ev.object_key as object_key',
                'ev.directory as directory',
                'ev.original_filename as original_filename',
                'ev.stored_filename as stored_filename',
                'ev.extension as extension',
                'ev.mime_type as mime_type',
                'ev.size as size',
                'ev.checksum as checksum',
                'ev.public_url as public_url',
                'ev.uploaded_at as uploaded_at',
                'd.id as owner_id',
                'd.title as owner_title',
                'e.episode_number as episode_number',
                DB::raw("'video' as kind"),
            ]);

        $assets = DB::table('drama_assets as da')
            ->leftJoin('dramas as d', 'd.id', '=', 'da.drama_id')
            ->select([
                DB::raw("'drama_asset' as source"),
                'da.id',
                'da.storage_provider_id',
                'da.object_key',
                'da.directory',
                'da.original_filename',
                'da.stored_filename',
                'da.extension',
                'da.mime_type',
                'da.size',
                'da.checksum',
                'da.public_url',
                'da.uploaded_at',
                'd.id',
                'd.title',
                DB::raw('NULL as episode_number'),
                'da.asset_type',
            ]);

        return $videos->unionAll($assets);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(QueryBuilder $query, array $filters): void
    {
        $keyword = trim((string) ($filters['q'] ?? ''));

        if ($keyword !== '') {

            // `escapeLike` supaya `%` dan `_` yang diketik admin dicari
            // sebagai karakter biasa. Tanpa itu, mengetik satu `%` akan
            // mencocokkan seluruh isi tabel dan terlihat seperti pencarian
            // yang rusak.
            $like = '%'.$this->escapeLike($keyword).'%';

            $query->where(function ($sub) use ($like) {
                $sub->where('original_filename', 'like', $like)
                    ->orWhere('stored_filename', 'like', $like)
                    ->orWhere('object_key', 'like', $like)
                    ->orWhere('owner_title', 'like', $like);
            });
        }

        if (in_array($filters['source'] ?? null, StoredFileSource::values(), true)) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['provider']) && ctype_digit((string) $filters['provider'])) {
            $query->where('storage_provider_id', (int) $filters['provider']);
        }

        if (! empty($filters['kind'])) {
            $query->where('kind', (string) $filters['kind']);
        }

        if (! empty($filters['ext'])) {
            $query->where('extension', Str::lower((string) $filters['ext']));
        }
    }

    /**
     * Kolom dan arah pengurutan, sudah divalidasi.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    protected function sortFrom(array $filters): array
    {
        $kolom = (string) ($filters['sort'] ?? 'uploaded_at');

        if (! array_key_exists($kolom, self::SORTABLE)) {
            $kolom = 'uploaded_at';
        }

        $arah = Str::lower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$kolom, $arah];
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Satu baris hasil UNION menjadi bentuk yang dipakai Blade.
     *
     * @return array<string, mixed>
     */
    protected function present(object $row): array
    {
        $source = StoredFileSource::tryFrom((string) $row->source);

        $provider = $this->providerCache()[(int) ($row->storage_provider_id ?? 0)] ?? null;

        return [
            'ref'        => $row->source.':'.$row->source_id,

            // Dipecah di sini, bukan di Blade. Route File Manager memakai dua
            // segmen (`{source}/{id}`), dan memanggil explode() di setiap
            // tombol di setiap baris adalah cara yang mudah menghasilkan satu
            // tombol yang argumennya tertukar tanpa ada yang menyadarinya.
            'source_key' => (string) $row->source,
            'source_id'  => (int) $row->source_id,

            'source'     => $source,
            'source_label' => $source?->label() ?? (string) $row->source,
            'icon'       => $source?->icon() ?? 'file',

            // Nama tanpa ekstensi, untuk mengisi kotak Ganti Nama. Ekstensinya
            // ditempelkan kembali oleh service, jadi yang ditawarkan ke admin
            // memang hanya bagian yang boleh diubah.
            'basename'   => pathinfo((string) $row->stored_filename, PATHINFO_FILENAME),
            'kind'       => (string) $row->kind,
            'kind_label' => $this->kindLabel((string) $row->kind),
            'stored_filename'   => (string) $row->stored_filename,
            'original_filename' => (string) $row->original_filename,
            'object_key' => (string) $row->object_key,
            'directory'  => (string) ($row->directory ?? ''),
            'extension'  => $row->extension ? Str::lower((string) $row->extension) : null,
            'mime_type'  => (string) $row->mime_type,
            'size'       => (int) $row->size,
            'size_human' => StorageMonitorService::bytesForHumans((int) $row->size),
            'public_url' => $row->public_url ?: null,

            // Query builder mengembalikan tanggal sebagai string, bukan
            // Carbon — hasil UNION tidak melewati cast model mana pun. Diubah
            // di sini supaya Blade tidak perlu tahu bedanya.
            'uploaded_at'=> $this->toDate($row->uploaded_at),
            'owner_id'   => $row->owner_id ? (int) $row->owner_id : null,
            'owner_title'=> $row->owner_title ?: null,
            'episode_number' => $row->episode_number !== null ? (int) $row->episode_number : null,
            'provider_id'    => $row->storage_provider_id ? (int) $row->storage_provider_id : null,
            'provider_name'  => $provider?->name,

            // Provider yang sudah dihapus permanen membuat berkasnya tidak
            // bisa dijangkau lagi: barisnya masih ada, objeknya masih ada di
            // bucket, tetapi aplikasi kehilangan kredensialnya. Baris seperti
            // itu ditampilkan tanpa tombol aksi, bukan disembunyikan —
            // menyembunyikannya berarti tidak ada yang tahu berkasnya masih
            // menghabiskan ruang berbayar.
            'reachable'      => $provider !== null,
            'previewable'    => $this->isImage((string) $row->mime_type, $row->extension),
        ];
    }

    /**
     * @return array<int, StorageProvider>
     */
    protected function providerCache(): array
    {
        if ($this->providers === null) {
            $this->providers = StorageProvider::query()
                ->get()
                ->keyBy(fn ($p) => (int) $p->getKey())
                ->all();
        }

        return $this->providers;
    }

    /**
     * String tanggal dari query builder menjadi Carbon, atau null.
     *
     * Nilai yang tidak bisa diurai dikembalikan sebagai `null` dan bukan
     * dilempar: satu baris dengan tanggal aneh tidak boleh mematikan seluruh
     * daftar berkas.
     */
    protected function toDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function isImage(string $mime, ?string $extension): bool
    {
        if (Str::startsWith($mime, 'image/')) {
            return true;
        }

        return in_array(
            Str::lower((string) $extension),
            ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'],
            true
        );
    }

    /**
     * Nama jenis berkas yang terbaca.
     *
     * `kind` berisi `video` untuk episode dan nilai `asset_type` untuk aset
     * drama. Yang kedua sudah punya label di `DramaAssetType`, jadi label itu
     * yang dipakai daripada menuliskan daftar kedua yang bisa berbeda.
     */
    public function kindLabel(string $kind): string
    {
        if ($kind === 'video') {
            return 'Video';
        }

        return DramaAssetType::tryFrom($kind)?->label() ?? Str::headline($kind);
    }

    /**
     * Pilihan jenis untuk penyaring, tanpa mengarang nilai yang tidak ada.
     *
     * @return array<string, string>
     */
    public function kindOptions(): array
    {
        $options = ['video' => 'Video'];

        foreach (DramaAssetType::ordered() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * Ekstensi yang benar-benar ada di database.
     *
     * Dibaca dari data, bukan didaftar di kode. Daftar tetap akan memuat
     * pilihan yang tidak menghasilkan apa-apa, dan kehilangan yang muncul
     * karena modul baru.
     *
     * @return array<int, string>
     */
    public function extensions(): array
    {
        $hasil = [];

        foreach (StoredFileSource::cases() as $source) {
            $hasil = array_merge($hasil, DB::table($source->table())
                ->whereNotNull('extension')
                ->distinct()
                ->pluck('extension')
                ->all());
        }

        $hasil = array_values(array_unique(array_map('strtolower', $hasil)));

        sort($hasil);

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Satu berkas
    |--------------------------------------------------------------------------
    */

    /**
     * Baris asli dari referensi berbentuk `sumber:id`.
     *
     * Referensi gabungan dipakai karena id tidak unik lintas tabel: baris
     * `episode_videos` nomor 3 dan `drama_assets` nomor 3 sama-sama ada, dan
     * URL yang hanya menyebut angka akan menunjuk keduanya sekaligus.
     *
     * @throws RuntimeException bila bentuknya salah atau barisnya tidak ada
     */
    public function locate(string $ref): Model
    {
        [$sumber, $id] = array_pad(explode(':', $ref, 2), 2, null);

        $source = StoredFileSource::tryFrom((string) $sumber);

        if ($source === null || ! ctype_digit((string) $id)) {
            throw new RuntimeException('Referensi berkas tidak dikenali: '.$ref);
        }

        $model = $source->model();

        $row = $model::query()->with($source->relations())->find((int) $id);

        if ($row === null) {
            throw new RuntimeException(
                'Berkas itu sudah tidak ada di database. Kemungkinan baru saja '
                .'dihapus dari halaman lain — muat ulang daftarnya.'
            );
        }

        return $row;
    }

    /** Sumber sebuah model, kebalikan dari `locate()`. */
    public function sourceOf(Model $file): StoredFileSource
    {
        return $file instanceof EpisodeVideo
            ? StoredFileSource::EPISODE_VIDEO
            : StoredFileSource::DRAMA_ASSET;
    }

    public function refOf(Model $file): string
    {
        return $this->sourceOf($file)->value.':'.$file->getKey();
    }

    /*
    |--------------------------------------------------------------------------
    | Operasi berkas — semuanya lewat Storage Engine
    |--------------------------------------------------------------------------
    */

    /**
     * Ganti nama berkas.
     *
     * Ekstensinya SELALU dipertahankan, apa pun yang diketik admin. Dua
     * sebabnya: `mime_type` yang sudah tersimpan tidak ikut berubah, sehingga
     * mengganti `.mp4` menjadi `.txt` menghasilkan baris yang isinya saling
     * bertentangan; dan daftar ekstensi terlarang di Storage Engine tidak
     * boleh bisa dilewati lewat jalur ganti nama.
     *
     * @throws StorageEngineException
     */
    public function rename(Model $file, string $newName): Model
    {
        $lama = (string) $file->object_key;

        $nama = $this->sanitiseBaseName($newName).($file->extension ? '.'.$file->extension : '');

        if ($nama === ObjectKey::basenameOf($lama)) {
            return $file;
        }

        $stored = $this->storage->rename(
            (int) $file->storage_provider_id,
            $lama,
            $nama
        );

        $this->syncRow($file, $stored, 'rename', $lama);

        return $file;
    }

    /**
     * Pindahkan berkas ke direktori lain di provider yang sama.
     *
     * Perpindahan ANTAR provider tidak disediakan. Itu operasi yang berbeda
     * sifatnya — perlu mengalirkan isi berkas dari satu penyimpanan ke
     * penyimpanan lain, tahan terhadap kegagalan di tengah jalan, dan untuk
     * video berukuran gigabyte berjalan lama sehingga tidak boleh berada di
     * dalam sebuah request. Tercatat di STATUS.md sebagai pekerjaan sendiri.
     *
     * @throws StorageEngineException
     */
    public function move(Model $file, string $directory): Model
    {
        $lama = (string) $file->object_key;

        $tujuan = ObjectKey::directory($directory);

        if ($tujuan === ObjectKey::directoryOf($lama)) {
            return $file;
        }

        $stored = $this->storage->move(
            (int) $file->storage_provider_id,
            $lama,
            $tujuan
        );

        $this->syncRow($file, $stored, 'move', $lama);

        return $file;
    }

    /**
     * Hapus berkas beserta barisnya.
     *
     * Objeknya dihapus lebih dulu. Urutan sebaliknya — baris dulu, objek
     * kemudian — akan meninggalkan objek yatim yang tidak lagi bisa ditemukan
     * lewat halaman mana pun begitu penghapusan objeknya gagal.
     *
     * Kalau objeknya gagal dihapus, barisnya TIDAK dihapus dan galatnya
     * dilempar. Ini kebalikan dari keputusan Sprint 7.6 pada Asset Manager,
     * dan sengaja: di sana yang dipentingkan adalah admin tidak melihat aset
     * yang dikiranya sudah hilang. Di sini, baris adalah satu-satunya catatan
     * bahwa objek itu ada — membuangnya berarti membuang satu-satunya cara
     * menemukan berkas yang gagal dihapus tadi.
     *
     * @throws StorageEngineException
     */
    public function delete(Model $file): void
    {
        $providerId = (int) $file->storage_provider_id;

        $key = (string) $file->object_key;

        $this->storage->delete($providerId, $key);

        if ($file instanceof EpisodeVideo) {
            $this->clearEpisodeVideoUrl($file);
        }

        $file->delete();

        $this->log('info', 'delete', $file, [
            'provider_id' => $providerId,
            'object_key'  => $key,
        ]);
    }

    /**
     * Perbarui baris setelah objeknya berpindah.
     *
     * Tiga kolom yang WAJIB ikut: `object_key`, `stored_filename`, dan
     * `directory`. Kalau salah satunya tertinggal, baris akan menunjuk ke
     * tempat berkasnya dulu berada — dan gejalanya "berkas hilang" tanpa satu
     * pun pesan galat, persis yang diperingatkan STATUS.md soal menyimpan key
     * tanpa provider.
     */
    protected function syncRow(Model $file, StoredFile $stored, string $operation, string $lama): void
    {
        $url = $this->urlSetelahPindah($file, $stored);

        $file->forceFill([
            'object_key'      => $stored->objectKey,
            'stored_filename' => $stored->fileName,
            'directory'       => $stored->directory(),
            'public_url'      => $url,
        ])->save();

        if ($file instanceof EpisodeVideo) {
            $this->syncEpisodeVideoUrl($file, $url);
        }

        $this->log('info', $operation, $file, [
            'dari'        => $lama,
            'ke'          => $stored->objectKey,
            'provider_id' => $stored->providerId,
        ]);
    }

    /**
     * URL publik berkas setelah object key-nya berpindah.
     *
     * Tidak bisa diambil begitu saja dari `StoredFile::url`, dan ini bug yang
     * sempat lolos sebelum ditulis ulang. `StorageEngine::relocate()` menyusun
     * hasilnya lewat `describe()`, yang membaca visibility dari penyimpanan —
     * dan sebagian provider tidak melaporkannya. Ketika itu terjadi, engine
     * jatuh ke `private`, `url` menjadi `null`, dan kolom `public_url` sebuah
     * poster yang tadinya terisi berubah jadi kosong hanya karena berkasnya
     * diganti nama. Gejalanya: gambar hilang dari beranda, tanpa satu pun
     * pesan galat di panel.
     *
     * Aturannya jadi: berkas yang TADINYA punya URL publik harus tetap
     * punya URL publik di alamat barunya. `StorageEngine::url()` menyusunnya
     * tanpa bergantung pada visibility yang dilaporkan disk.
     */
    protected function urlSetelahPindah(Model $file, StoredFile $stored): ?string
    {
        if ($stored->url !== null) {
            return $stored->url;
        }

        if (! filled($file->public_url)) {
            return null;
        }

        try {
            return $this->storage->url($stored->providerId, $stored->objectKey);
        } catch (StorageEngineException) {
            // Gagal menyusun URL tidak boleh membatalkan perpindahan yang
            // sudah berhasil. Yang hilang hanya satu kolom keterangan.
            return null;
        }
    }

    /**
     * `episodes.video_url` ikut menyesuaikan.
     *
     * Kolom itu dipakai pemutar yang sudah ada sejak sebelum multi storage.
     * Kalau tidak ikut diperbarui, memindahkan berkas video akan membuat
     * pemutar tetap meminta alamat lama — dan yang muncul di pengguna adalah
     * pemutar yang gagal memuat, bukan pesan apa pun di panel.
     */
    protected function syncEpisodeVideoUrl(EpisodeVideo $video, ?string $url): void
    {
        $video->episode?->update(['video_url' => $url]);
    }

    protected function clearEpisodeVideoUrl(EpisodeVideo $video): void
    {
        $video->episode?->update(['video_url' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pratayang dan unduh
    |--------------------------------------------------------------------------
    */

    /**
     * Alamat yang bisa dipasang di `<img src>` atau disalin admin.
     *
     * Urutannya: URL publik yang sudah tersimpan, lalu URL bertanda tangan
     * yang berumur pendek, lalu `null`.
     *
     * `strict: false` supaya provider yang memang tidak bisa membuat tanda
     * tangan — penyimpanan lokal — menghasilkan `null` dan bukan exception.
     * Tidak adanya URL bukan kegagalan; halaman menyediakan tombol Unduh yang
     * mengalirkan isinya lewat engine untuk kasus itu.
     */
    public function shareUrl(Model $file, int $minutes = 30): ?string
    {
        if (filled($file->public_url)) {
            return (string) $file->public_url;
        }

        if ($file->storage_provider_id === null) {
            return null;
        }

        try {
            return $this->storage->temporaryUrl(
                (int) $file->storage_provider_id,
                (string) $file->object_key,
                $minutes,
                false
            );
        } catch (StorageEngineException) {
            // Provider yang tidak siap tidak boleh menggagalkan seluruh
            // halaman hanya karena satu tautan tidak bisa dibuat.
            return null;
        }
    }

    /**
     * Aliran isi berkas untuk diunduh.
     *
     * @return resource|null
     *
     * @throws StorageEngineException
     */
    public function stream(Model $file)
    {
        return $this->storage->readStream(
            (int) $file->storage_provider_id,
            (string) $file->object_key
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Bersihkan nama yang diketik admin menjadi basename yang aman.
     *
     * Titik dibuang seluruhnya, bukan sebagian: nama seperti `poster.php` dan
     * `poster.jpg.php` sama-sama kehilangan bagian setelah titik pertama, dan
     * ekstensi yang sah ditempelkan kembali oleh pemanggil dari kolom
     * `extension` yang sudah tersimpan.
     */
    protected function sanitiseBaseName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));

        $name = Str::before($name, '.');

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';

        $name = trim((string) $name, '-._');

        return $name === '' ? 'berkas' : Str::limit($name, 120, '');
    }

    protected function log(string $level, string $event, Model $file, array $context): void
    {
        Log::channel(config('storage.engine.log_channel') ?: config('logging.default'))
            ->log($level, 'file.manager.'.$event, $context + [
                'source' => $this->sourceOf($file)->value,
                'row_id' => $file->getKey(),
            ]);
    }
}
