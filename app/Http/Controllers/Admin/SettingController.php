<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ActivityLogger;
use App\Services\Admin\MediaService;
use App\Services\Admin\SettingService;
use App\Support\ChannelTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(
        protected SettingService $settings,
        protected MediaService $media
    ) {
    }

    public function index(): View
    {
        return view('web.pages.admin.settings', [
            'groups'  => SettingService::GROUPS,
            'schema'  => $this->settings->grouped(),
            'values'  => $this->settings->all(),

            // Template channel siap pakai. Dikirim ke view supaya bisa
            // ditempelkan ke kolomnya dengan satu klik, tanpa perjalanan ke
            // server — kolomnya baru tersimpan saat tombol Simpan ditekan,
            // sama seperti pengaturan lain di halaman ini.
            'channelTemplates' => ChannelTemplates::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (SettingService::SCHEMA as $key => [$group, $label, $type]) {
            $rules[$key] = match ($type) {
                'image'    => MediaService::rules(),
                'boolean'  => ['nullable', 'boolean'],
                'textarea' => ['nullable', 'string', 'max:2000'],
                default    => ['nullable', 'string', 'max:255'],
            };
        }

        $request->validate($rules);

        $values = [];

        foreach (SettingService::SCHEMA as $key => [$group, $label, $type]) {
            if ($type === 'image') {
                if ($request->hasFile($key)) {
                    $values[$key] = $this->media->store(
                        $request->file($key),
                        'settings',
                        $this->settings->get($key)
                    );
                }

                continue;
            }

            $values[$key] = $type === 'boolean'
                ? ($request->boolean($key) ? '1' : '0')
                : $request->input($key);
        }

        $this->settings->put($values);

        app(ActivityLogger::class)->log('diubah', 'settings');

        return back()->with('status', 'Pengaturan tersimpan.');
    }
}
