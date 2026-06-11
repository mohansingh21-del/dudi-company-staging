<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendEmailNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $providerId;
    protected int $requestId;

    public function __construct(int $providerId, int $requestId)
    {
        $this->providerId = $providerId;
        $this->requestId = $requestId;
    }

    public function handle(): void
    {
        Log::info("Email notification sent to provider {$this->providerId} for request {$this->requestId}");
    }
}
