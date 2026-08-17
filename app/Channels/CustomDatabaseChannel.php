<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomDatabaseChannel
{
    public function send($notifiable, Notification $notification)
    {
        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($notifiable)
            : $notification->toArray($notifiable);

        return DB::table('notifications')->insert([
            'id' => $notification->id ?? Str::uuid()->toString(),
            'user_id' => $notifiable->id,
            'type' => get_class($notification),
            'data' => json_encode($data),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
