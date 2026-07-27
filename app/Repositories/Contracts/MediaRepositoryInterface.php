<?php

namespace App\Repositories\Contracts;

use Illuminate\Http\UploadedFile;

interface MediaRepositoryInterface
{
    public function upload(
        UploadedFile $file,
        string $directory,
        ?int $userId=null
    );

    public function delete(int $id);

    public function find(int $id);
}