<?php

namespace App\Models;

use App\Enums\TelegramMenuAction;
use Illuminate\Database\Eloquent\Model;

class TelegramMenu extends Model
{
    protected $fillable = [
        'label',
        'action',
        'url',
        'row',
        'position',
        'is_active',
    ];

    protected $casts = [
        'action'    => TelegramMenuAction::class,
        'row'       => 'integer',
        'position'  => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Bentuk satu tombol untuk inline_keyboard Telegram.
     *
     * Tombol tautan memakai `url`, sisanya memakai `callback_data`. Satu
     * tombol tidak boleh punya keduanya — Telegram menolak seluruh keyboard,
     * bukan hanya tombolnya.
     */
    public function toButton(): array
    {
        if ($this->action->isLink()) {
            return [
                'text' => $this->label,
                'url'  => $this->url,
            ];
        }

        return [
            'text'          => $this->label,
            'callback_data' => $this->action->value,
        ];
    }
}
