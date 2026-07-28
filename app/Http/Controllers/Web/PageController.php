<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Halaman statis: tentang, bantuan, privasi, ketentuan.
 * Satu controller + satu view generik agar tidak ada duplikasi.
 */
class PageController extends Controller
{
    public function about(): View
    {
        return $this->page('about', 'Tentang DramaVerse ID');
    }

    public function help(): View
    {
        return $this->page('help', 'Pusat Bantuan');
    }

    public function privacy(): View
    {
        return $this->page('privacy', 'Kebijakan Privasi');
    }

    public function terms(): View
    {
        return $this->page('terms', 'Ketentuan Layanan');
    }

    private function page(string $slug, string $title): View
    {
        return view("web.pages.static.{$slug}", compact('title'));
    }
}
