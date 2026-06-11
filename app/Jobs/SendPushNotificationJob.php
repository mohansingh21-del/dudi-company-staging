<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $providerId;
    protected string $title;
    protected string $body;
    protected array $data;

    public function __construct(int $providerId, string $title, string $body, array $data)
    {
        $this->providerId = $providerId;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    public function handle(): void
    {
        Log::info("Push notification sent to provider {$this->providerId}: {$this->title} - {$this->body}", $this->data);
    }
}
