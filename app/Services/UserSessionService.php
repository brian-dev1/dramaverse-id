<?php

namespace App\Services;

use App\Repositories\UserSessionRepository;

class UserSessionService
{
    public function __construct(
        protected UserSessionRepository $sessions
    ) {
    }

    public function current(int $userId): ?string
    {
        return $this->sessions
            ->get($userId)
            ?->state;
    }

    /**
     * Data yang dititipkan bersama state.
     *
     * Alur yang butuh mengingat sesuatu antar pesan — nomor tagihan yang
     * buktinya sedang ditunggu, misalnya — menyimpannya di sini, bukan di
     * cache. Cache boleh hilang kapan saja; percakapan yang setengah jalan
     * tidak boleh.
     *
     * @return array<string,mixed>
     */
    public function payload(int $userId): array
    {
        $payload = $this->sessions->get($userId)?->payload;

        return is_array($payload) ? $payload : [];
    }

    public function set(
        int $userId,
        string $state,
        array $payload = []
    ): void {

        $this->sessions->set(
            $userId,
            $state,
            $payload
        );
    }

    public function clear(int $userId): void
    {
        $this->sessions->clear($userId);
    }
}