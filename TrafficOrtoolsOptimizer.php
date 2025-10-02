<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrafficOrtoolsOptimizer
{
    protected string $serviceUrl;

    public function __construct()
    {
        $this->serviceUrl = config('settings.uapp_api.maps.or_tools_url', 'https://api.uapp.bg/ortools');
    }

    /**
     * Async optimization job request
     */
    public function optimizeAsync(
        array $locations,
        array $serviceTimes,
        array $priorities,
        array $timeWindows,
        array $vehicleCapacities,
        array $demands,
        int $numVehicles,
        bool $returnRoutes = false,
        ?array $starts = null,
        ?array $ends = null
    ): array {
        try {
            $payload = [
                'locations' => $locations,
                'service_times' => $serviceTimes,
                'priorities' => $priorities,
                'time_windows' => $timeWindows,
                'vehicle_capacities' => $vehicleCapacities,
                'demands' => $demands,
                'num_vehicles' => $numVehicles,
                'return_routes' => $returnRoutes,
            ];

            if ($starts !== null) $payload['starts'] = $starts;
            if ($ends !== null) $payload['ends'] = $ends;

            $response = Http::post("{$this->serviceUrl}/ortools/optimize-async", $payload);

            if ($response->successful()) return $response->json();

            Log::error('OR-Tools async optimize failed', [
                'payload' => $payload,
                'response' => $response->body()
            ]);

            return ['status' => 'error', 'message' => 'Optimization request failed'];
        } catch (\Throwable $e) {
            Log::error('OR-Tools optimize exception', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Poll job status
     */
    public function getJobStatus(string $jobId): array
    {
        try {
            $response = Http::get("{$this->serviceUrl}/ortools/job-status/{$jobId}");

            if ($response->successful()) return $response->json();

            return ['status' => 'error', 'message' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('OR-Tools job status exception', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
