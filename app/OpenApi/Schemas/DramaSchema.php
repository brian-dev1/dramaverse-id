<?php

namespace App\OpenApi\Schemas;

/**
 * @OA\Schema(
 *     schema="Drama",
 *     type="object",
 *     required={"id","title"},
 *
 *     @OA\Property(
 *          property="id",
 *          type="integer",
 *          example=1
 *     ),
 *
 *     @OA\Property(
 *          property="title",
 *          type="string",
 *          example="Hidden Love"
 *     ),
 *
 *     @OA\Property(
 *          property="thumbnail",
 *          type="string",
 *          example="https://example.com/image.jpg"
 *     ),
 *
 *     @OA\Property(
 *          property="rating",
 *          type="number",
 *          format="float",
 *          example=4.8
 *     )
 * )
 */
class DramaSchema
{
}