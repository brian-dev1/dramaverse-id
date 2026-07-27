<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use App\Repositories\Contracts\MediaRepositoryInterface;

class MediaService
{
    public function __construct(
        protected MediaRepositoryInterface $repository
    ){
    }

    public function upload(
        UploadedFile $file,
        string $directory,
        ?int $userId=null
    ){

        return $this->repository->upload(

            $file,

            $directory,

            $userId

        );

    }

    public function delete(int $id)
    {
        return $this->repository->delete($id);
    }

    public function find(int $id)
    {
        return $this->repository->find($id);
    }
}