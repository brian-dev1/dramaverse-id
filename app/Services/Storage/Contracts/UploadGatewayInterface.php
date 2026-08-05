<?php

namespace App\Services\Storage\Contracts;

use App\Models\StorageProvider;

interface UploadGatewayInterface
{
    /**
     * Mengembalikan provider tujuan upload.
     */
    public function uploadProvider(): StorageProvider;
}