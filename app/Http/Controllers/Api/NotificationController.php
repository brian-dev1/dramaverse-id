<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $service
    ) {
    }

    /**
     * List Notification.
     */
    public function index()
    {
        return response()->json([

            'success' => true,

            'data' => NotificationResource::collection(

                $this->service->all(

                    auth()->user()

                )

            ),

        ]);
    }

    /**
     * Read Notification.
     */
    public function read(
        Notification $notification
    ) {

        abort_if(

            $notification->user_id !== auth()->id(),

            403

        );

        return response()->json([

            'success' => true,

            'data' => new NotificationResource(

                $this->service->markAsRead(

                    $notification

                )

            ),

        ]);

    }

    /**
     * Read All Notification.
     */
    public function readAll()
    {
        $count = $this->service->markAllAsRead(

            auth()->user()

        );

        return response()->json([

            'success' => true,

            'updated' => $count,

        ]);
    }
}