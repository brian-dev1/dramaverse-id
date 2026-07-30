<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TelegramMenuAction;
use App\Http\Controllers\Controller;
use App\Models\TelegramMenu;
use App\Services\Admin\ActivityLogger;
use App\Services\TelegramMenuService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengaturan menu tombol bot Telegram.
 *
 * Satu halaman untuk seluruh susunan: menyunting semua baris sekaligus,
 * menambah satu tombol, dan menghapus. Susunan menu adalah satu kesatuan —
 * memindahkan satu tombol hampir selalu berarti menggeser tetangganya, dan
 * menyimpannya satu per satu membuat keadaan setengah jadi terlihat oleh
 * pengguna bot di antara dua penyimpanan.
 *
 * Teknik form yang sama dengan editor prioritas Storage Manager (7.2D): satu
 * form untuk semua baris, dan form hapus per baris berada DI LUAR tabel lalu
 * dihubungkan dengan atribut `form`. Form bersarang dibuang parser HTML —
 * bug yang masih tercatat di STATUS.md untuk modul CRUD lain.
 */
class TelegramMenuController extends Controller
{
    public function __construct(
        protected TelegramMenuService $menus
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.telegram-menu', [
            'menus'    => $this->menus->all(),
            'actions'  => TelegramMenuAction::options(),
            'preview'  => $this->menus->keyboard()['inline_keyboard'] ?? [],
        ]);
    }

    /** Simpan seluruh susunan sekaligus. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'menus'             => ['array'],
            'menus.*.label'     => ['required', 'string', 'max:64'],
            'menus.*.action'    => ['required', Rule::enum(TelegramMenuAction::class)],
            'menus.*.url'       => ['nullable', 'string', 'max:255', 'url'],
            'menus.*.row'       => ['required', 'integer', 'min:1', 'max:20'],
            'menus.*.position'  => ['required', 'integer', 'min:1', 'max:8'],
            'menus.*.is_active' => ['nullable', 'boolean'],
        ], [], $this->attributeNames($request));

        $diubah = 0;

        foreach ($data['menus'] ?? [] as $id => $isi) {

            $menu = TelegramMenu::find($id);

            if ($menu === null) {
                continue;
            }

            if ($gagal = $this->linkTanpaUrl($isi)) {
                return back()->with('error', $gagal)->withInput();
            }

            /*
            |------------------------------------------------------------------
            | Tombol terkunci
            |------------------------------------------------------------------
            |
            | Perbuatan dan status aktifnya dipaksa tetap, apa pun yang datang
            | dari formulir. Penjagaannya ada di sini, bukan hanya di tampilan:
            | field yang di-disable tidak ikut terkirim, tetapi permintaan yang
            | dirakit tangan tetap bisa membawa nilai apa saja.
            |
            */

            $terkunci = $menu->action->isLocked();

            $action = $terkunci ? $menu->action->value : $isi['action'];

            $menu->fill([
                'label'     => $isi['label'],
                'action'    => $action,
                'url'       => $action === TelegramMenuAction::URL->value ? $isi['url'] : null,
                'row'       => $isi['row'],
                'position'  => $isi['position'],
                'is_active' => $terkunci ? true : (bool) ($isi['is_active'] ?? false),
            ]);

            // Hanya yang benar-benar berubah yang ditulis, supaya
            // `updated_at` tetap berarti sesuatu.
            if ($menu->isDirty()) {
                $menu->save();

                $diubah++;
            }
        }

        $this->menus->forget();

        app(ActivityLogger::class)->log('update', 'telegram-menu', null, ['jumlah' => $diubah]);

        return back()->with('status', $diubah === 0
            ? 'Tidak ada yang berubah.'
            : "{$diubah} tombol diperbarui. Menu bot akan memakai susunan baru pada pesan berikutnya.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label'    => ['required', 'string', 'max:64'],
            'action'   => ['required', Rule::enum(TelegramMenuAction::class)],
            'url'      => ['nullable', 'string', 'max:255', 'url'],
            'row'      => ['required', 'integer', 'min:1', 'max:20'],
            'position' => ['required', 'integer', 'min:1', 'max:8'],
        ]);

        if ($gagal = $this->linkTanpaUrl($data)) {
            return back()->with('error', $gagal)->withInput();
        }

        TelegramMenu::create([
            'label'     => $data['label'],
            'action'    => $data['action'],
            'url'       => $data['action'] === TelegramMenuAction::URL->value ? $data['url'] : null,
            'row'       => $data['row'],
            'position'  => $data['position'],
            'is_active' => true,
        ]);

        $this->menus->forget();

        app(ActivityLogger::class)->log('create', 'telegram-menu', null, ['label' => $data['label']]);

        return back()->with('status', 'Tombol ditambahkan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $menu = TelegramMenu::findOrFail($id);

        if ($menu->action->isLocked()) {
            return back()->with(
                'error',
                'Tombol "'.$menu->label.'" tidak bisa dihapus. Itu satu-satunya '
                .'jalan pengguna masuk ke situs — tidak ada login email untuk '
                .'pengguna biasa. Labelnya boleh diganti, letaknya boleh dipindah.'
            );
        }

        $label = $menu->label;

        $menu->delete();

        $this->menus->forget();

        app(ActivityLogger::class)->log('delete', 'telegram-menu', null, ['id' => $id, 'label' => $label]);

        return back()->with('status', "Tombol \"{$label}\" dihapus.");
    }

    /** Kembalikan susunan bawaan tanpa menghapus yang sudah ada. */
    public function reset(): RedirectResponse
    {
        app(\Database\Seeders\TelegramMenuSeeder::class)->run();

        app(ActivityLogger::class)->log('update', 'telegram-menu', null, ['aksi' => 'pulihkan bawaan']);

        return back()->with(
            'status',
            'Tombol bawaan yang hilang sudah dikembalikan. Tombol yang sudah Anda ubah tidak disentuh.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */

    /**
     * Tombol tautan tanpa alamat membuat Telegram menolak SELURUH keyboard,
     * bukan hanya tombol itu. Ditolak di sini supaya menunya tidak hilang
     * seluruhnya setelah disimpan.
     */
    private function linkTanpaUrl(array $isi): ?string
    {
        if (($isi['action'] ?? null) !== TelegramMenuAction::URL->value) {
            return null;
        }

        if (filled($isi['url'] ?? null)) {
            return null;
        }

        return 'Tombol dengan perbuatan "Tautan bebas" wajib punya URL. '
            .'Telegram menolak seluruh menu bila ada satu tombol tautan tanpa alamat.';
    }

    /** Nama field yang terbaca manusia pada pesan validasi. */
    private function attributeNames(Request $request): array
    {
        $nama = [];

        foreach (array_keys($request->input('menus', [])) as $id) {
            $nama["menus.{$id}.label"]    = 'label tombol';
            $nama["menus.{$id}.url"]      = 'URL tombol';
            $nama["menus.{$id}.row"]      = 'baris';
            $nama["menus.{$id}.position"] = 'posisi';
        }

        return $nama;
    }
}
