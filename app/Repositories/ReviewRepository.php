<?php

namespace App\Repositories;

use App\Models\Review;
use App\Repositories\Contracts\ReviewRepositoryInterface;

class ReviewRepository implements ReviewRepositoryInterface
{
    public function create(array $data)
    {
        return Review::create($data);
    }

    public function update($review,array $data)
    {
        $review->update($data);

        return $review;
    }

    public function delete($review)
    {
        $review->delete();
    }

    public function byDrama(int $dramaId)
    {
        return Review::where('drama_id',$dramaId)

            ->where('is_hidden',false)

            ->latest()

            ->paginate(20);
    }

    public function average(int $dramaId)
    {
        return Review::where('drama_id',$dramaId)

            ->avg('rating');
    }
}