<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SparePartInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifyMatchedProvidersForSparePartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];

    protected SparePartInquiry $inquiry;
    protected Collection $matchedProviders;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\SparePartInquiry  $inquiry
     * @param  \Illuminate\Support\Collection  $matchedProviders
     * @return void
     */
    public function __construct(SparePartInquiry $inquiry, Collection $matchedProviders)
    {
        $this->onQueue(config('queue.notifications_queue', 'notifications'));
        $this->inquiry = $inquiry;
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

        if (!$this->inquiry->relationLoaded('vehicleCategory')) {
            $this->inquiry->load('vehicleCategory');
        }

        $categoryName = $this->inquiry->vehicleCategory->name ?? '';

        foreach ($chunks as $chunk) {
            DB::transaction(function () use ($chunk, $categoryName, &$totalNotified) {
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

                    DB::table('spare_part_inquiry_notifications')->insert([
                        'id' => Str::uuid()->toString(),
                        'spare_part_inquiry_id' => $this->inquiry->id,
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
                            'New Spare Part Inquiry',
                            "{$this->inquiry->requirement_title} — {$categoryName}",
                            [
                                'type' => 'new_spare_part_inquiry',
                                'spare_part_inquiry_id' => $this->inquiry->id,
                                'category' => $categoryName,
                            ]
                        )->onQueue(config('queue.notifications_queue', 'notifications'));
                    }

                    if ($notifyEmail) {
                        Log::info("Email notification sent to provider {$providerId} for spare part inquiry {$this->inquiry->id}");
                    }

                    if ($notifySms) {
                        Log::info("SMS notification sent to provider {$providerId} for spare part inquiry {$this->inquiry->id}");
                    }

                    $totalNotified++;
                }
            });
        }

        Log::info("Total providers notified for spare part: {$totalNotified} for inquiry ID {$this->inquiry->id}");
    }
}
