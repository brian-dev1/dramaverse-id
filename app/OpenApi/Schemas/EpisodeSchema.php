<?php

namespace App\OpenApi\Schemas;

/**
 * @OA\Schema(
 *     schema="Episode",
 *     type="object",
 *
 *     @OA\Property(
 *          property="id",
 *          type="integer"
 *     ),
 *
 *     @OA\Property(
 *          property="episode_number",
 *          type="integer"
 *     ),
 *
 *     @OA\Property(
 *          property="title",
 *          type="string"
 *     ),
 *
 *     @OA\Property(
 *          property="video_url",
 *          type="string"
 *     )
 * )
 */
class EpisodeSchema
{
}