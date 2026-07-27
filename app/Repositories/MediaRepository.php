<?php

namespace App\Repositories;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use App\Repositories\Contracts\MediaRepositoryInterface;

class MediaRepository implements MediaRepositoryInterface
{
    public function upload(
        UploadedFile $file,
        string $directory,
        ?int $userId=null
    ){

        $path=$file->store($directory,'public');

        return Media::create([

            'disk'=>'public',

            'directory'=>$directory,

            'filename'=>basename($path),

            'original_name'=>$file->getClientOriginalName(),

            'mime_type'=>$file->getMimeType(),

            'extension'=>$file->getClientOriginalExtension(),

            'size'=>$file->getSize(),

            'url'=>Storage::disk('public')->url($path),

            'uploaded_by'=>$userId,

        ]);

    }

    public function delete(int $id)
    {

        $media=Media::findOrFail($id);

        Storage::disk($media->disk)

            ->delete(

                $media->directory.'/'.$media->filename

            );

        $media->delete();

    }

    public function find(int $id)
    {
        return Media::findOrFail($id);
    }
}