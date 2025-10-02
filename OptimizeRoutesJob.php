<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\RoutePlannerService;
use Illuminate\Support\Facades\Log;

class OptimizeRoutesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $locations;
    public ?string $orToolsJobId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(array $locations = [])
    {
        $this->locations = $locations;
    }

    /**
     * Run the OR-Tools async optimization job (queue worker).
     */
    public function handle(RoutePlannerService $planner)
    {
        $data = $this->locations ?: $planner->prepareDataForOptimization();

        $result = $planner->optimizeAsync($data);

        $this->orToolsJobId = $result['job_id'] ?? null;

        if (!$this->orToolsJobId) {
            Log::error('OR-Tools optimization failed', ['result' => $result]);
        }

        // Optionally: store $this->orToolsJobId in DB for tracking
    }

    /**
     * Dispatch OR-Tools optimization immediately (no queue)
     */
    public static function dispatchNow(RoutePlannerService $planner, array $locations = []): ?string
    {
        $data = $locations ?: $planner->prepareDataForOptimization();

        // Call OR-Tools async endpoint directly
        $result = $planner->optimizeAsync($data);

        if (empty($result['job_id'])) {
            Log::error('OR-Tools dispatchNow failed', ['result' => $result]);
        }

        return $result['job_id'] ?? null;
    }
}
