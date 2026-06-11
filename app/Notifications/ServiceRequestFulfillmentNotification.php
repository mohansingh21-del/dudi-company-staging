<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

use App\Models\ServiceRequest;
use App\Channels\CustomDatabaseChannel;
use App\Channels\FcmChannel;

class ServiceRequestFulfillmentNotification extends Notification
{
    use Queueable;

    public $serviceRequest;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(ServiceRequest $serviceRequest)
    {
        $this->serviceRequest = $serviceRequest;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [CustomDatabaseChannel::class, FcmChannel::class];
    }

    /**
     * Get the FCM push representation.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toFcm($notifiable)
    {
        return [
            'title' => 'Service Update',
            'body' => 'Has your service request been fulfilled? Let us know.',
            'data' => [
                'request_id' => $this->serviceRequest->id,
                'type' => 'fulfillment_check',
            ]
        ];
    }

    /**
     * Get the database representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toDatabase($notifiable)
    {
        return [
            'request_id' => $this->serviceRequest->id,
            'message' => 'Has your service request been fulfilled? Let us know so we can close it.',
            'type' => 'fulfillment_check',
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return $this->toDatabase($notifiable);
    }
}
