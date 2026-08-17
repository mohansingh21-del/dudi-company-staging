<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class FcmChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (!method_exists($notification, 'toFcm')) {
            return;
        }

        $message = $notification->toFcm($notifiable);

        // Stub/mock for sending push notification using client's FCM configuration
        Log::info("Sending FCM Push Notification to User {$notifiable->id}: " . json_encode($message));

        return true;
    }
}
