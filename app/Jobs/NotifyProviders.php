<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyProviders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $providerIds;
    public $serviceRequestId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $providerIds, $serviceRequestId)
    {
        $this->providerIds = $providerIds;
        $this->serviceRequestId = $serviceRequestId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        foreach ($this->providerIds as $providerId) {
            \Illuminate\Support\Facades\Log::info("Notifying provider {$providerId} about new service request {$this->serviceRequestId}");

            \App\Models\RequestNotification::firstOrCreate(
                [
                    'request_id' => $this->serviceRequestId,
                    'provider_id' => $providerId,
                ],
                [
                    'channels_sent' => ['email' => true, 'sms' => false, 'push' => false],
                ]
            );

            // TODO: dispatch real notification (email, SMS, push) to the provider
        }
    }
}
