<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifyMatchedProvidersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    protected ServiceRequest $serviceRequest;
    protected Collection $matchedProviders;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\ServiceRequest  $serviceRequest
     * @param  \Illuminate\Support\Collection  $matchedProviders
     * @return void
     */
    public function __construct(ServiceRequest $serviceRequest, Collection $matchedProviders)
    {
        $this->onQueue(config('queue.notifications_queue', 'notifications'));
        $this->serviceRequest = $serviceRequest;
        $this->matchedProviders = $matchedProviders;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $chunks = $this->matchedProviders->chunk(50);
        $totalNotified = 0;

        if (!$this->serviceRequest->relationLoaded('vehicleCategory')) {
            $this->serviceRequest->load('vehicleCategory');
        }
        if (!$this->serviceRequest->relationLoaded('serviceType')) {
            $this->serviceRequest->load('serviceType');
        }

        $categoryName = $this->serviceRequest->vehicleCategory->name ?? '';
        $serviceTypeName = $this->serviceRequest->serviceType->name ?? '';

        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($chunk, $categoryName, $serviceTypeName, &$totalNotified) {
                foreach ($chunk as $provider) {
                    $providerId = (int) $provider->provider_id;
                    $notifyPush = (bool) ($provider->notify_push ?? false);
                    $notifyEmail = (bool) ($provider->notify_email ?? false);
                    $notifySms = (bool) ($provider->notify_sms ?? false);

                    $channels = [
                        'sms' => $notifySms,
                        'email' => $notifyEmail,
                        'push' => $notifyPush,
                    ];

                    DB::table('request_notifications')->insert([
                        'id' => Str::uuid()->toString(),
                        'request_id' => $this->serviceRequest->id,
                        'provider_id' => $providerId,
                        'notified_at' => now(),
                        'channels_sent' => json_encode($channels),
                        'is_eligible' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($notifyPush) {
                        SendPushNotificationJob::dispatch(
                            $providerId,
                            'New Service Request',
                            "{$this->serviceRequest->title} — {$categoryName}",
                            [
                                'type' => 'new_service_request',
                                'request_id' => $this->serviceRequest->id,
                                'category' => $categoryName,
                                'service_type' => $serviceTypeName,
                            ]
                        )->onQueue(config('queue.notifications_queue', 'notifications'));
                    }

                    if ($notifyEmail) {
                        SendEmailNotificationJob::dispatch($providerId, $this->serviceRequest->id)
                            ->onQueue(config('queue.notifications_queue', 'notifications'));
                    }

                    if ($notifySms) {
                        SendSmsNotificationJob::dispatch($providerId, $this->serviceRequest->id)
                            ->onQueue(config('queue.notifications_queue', 'notifications'));
                    }

                    $totalNotified++;
                }
            });
        }

        Log::info("Total providers notified: {$totalNotified} for request ID {$this->serviceRequest->id}");
    }
}
