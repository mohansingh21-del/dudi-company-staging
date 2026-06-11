<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\ServiceRequest;
use App\Notifications\ServiceRequestFulfillmentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendFulfillmentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $count = 0;
        
        ServiceRequest::where('status', 'open')
            ->where('opened_at', '<=', now()->subHours(48))
            ->with('customer')
            ->chunk(100, function ($requests) use (&$count) {
                DB::transaction(function () use ($requests, &$count) {
                    $ids = $requests->pluck('id');
                    
                    ServiceRequest::whereIn('id', $ids)->update([
                        'status' => 'notified',
                        'notified_at' => now(),
                    ]);
                    
                    foreach ($requests as $request) {
                        if ($request->customer) {
                            $request->customer->notify(new ServiceRequestFulfillmentNotification($request));
                            $count++;
                        }
                    }
                });
            });

        Log::info("SendFulfillmentNotificationJob completed. Total notified: {$count}");
    }
}
