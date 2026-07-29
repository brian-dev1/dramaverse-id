<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Mencatat tindakan admin ke tabel activity_logs.
 *
 * Dipanggil dari base controller sehingga setiap CRUD tercatat tanpa
 * perlu diulang di tiap turunan.
 */
class ActivityLogger
{
    /**
     * @param  string  $action   dibuat | diubah | dihapus | dipulihkan | massal
     * @param  string  $module   nama modul, mis. "drama"
     */
    public function log(string $action, string $module, ?Model $subject = null, array $payload = []): void
    {
        $description = $subject
            ? sprintf('%s %s: %s', ucfirst($module), $action, $this->label($subject))
            : sprintf('%s %s', ucfirst($module), $action);

        ActivityLog::create([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'module'      => $module,
            'description' => $description,
            'ip_address'  => Request::ip(),
            'user_agent'  => Str::limit((string) Request::userAgent(), 500, ''),
            'payload'     => $payload ?: null,
        ]);
    }

    /** Label yang bisa dibaca manusia untuk sebuah record. */
    private function label(Model $subject): string
    {
        foreach (['title', 'name', 'key'] as $field) {
            if (! empty($subject->{$field})) {
                return (string) $subject->{$field};
            }
        }

        return '#'.$subject->getKey();
    }
}
