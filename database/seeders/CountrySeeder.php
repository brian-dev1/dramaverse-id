<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['Korea Selatan', 'KR', "\u{1F1F0}\u{1F1F7}"],
            ['Tiongkok',      'CN', "\u{1F1E8}\u{1F1F3}"],
            ['Thailand',      'TH', "\u{1F1F9}\u{1F1ED}"],
            ['Jepang',        'JP', "\u{1F1EF}\u{1F1F5}"],
            ['Taiwan',        'TW', "\u{1F1F9}\u{1F1FC}"],
            ['Filipina',      'PH', "\u{1F1F5}\u{1F1ED}"],
        ];

        foreach ($countries as $i => [$name, $code, $flag]) {
            Country::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'       => $name,
                    'code'       => $code,
                    'flag_emoji' => $flag,
                    'sort_order' => $i + 1,
                    'is_active'  => true,
                ]
            );
        }
    }
}
