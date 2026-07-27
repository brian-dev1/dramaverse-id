<?php

namespace App\OpenApi\Responses;

/**
 * @OA\Schema(
 *     schema="SuccessResponse",
 *
 *     @OA\Property(
 *          property="success",
 *          type="boolean",
 *          example=true
 *     ),
 *
 *     @OA\Property(
 *          property="message",
 *          type="string",
 *          example="Success"
 *     )
 * )
 */
class SuccessResponse
{
}