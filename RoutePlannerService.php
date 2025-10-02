<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoutePlannerService
{
    protected string $apiUrl;

    public function __construct()
    {
        // OR-Tools Python microservice endpoint
        $this->apiUrl = config('settings.uapp_api.maps.or_tools_url', 'https://api.uapp.bg/ortools');
    }

    /**
     * Prepare canonical input for optimization
     */
    public function prepareDataForOptimization(): array
    {
        $data = app()->make('App\Http\Controllers\Backend\DispatchersController')->getOrdersDataForOptimization();

        $warehouseLocations = $data['warehouseLocations'] ?? [];
        $orders = $data['orders'] ?? [];
        $inventories = $data['inventories'] ?? [];

        $locations = [];
        $vehicle_capacities = [];
        $demands = [];

        $productIds = collect($inventories)->flatten(1)->pluck('product_id')->unique()->values()->toArray();
        $productIndex = array_flip($productIds);

        // Build multi-depot structure
        foreach ($warehouseLocations as $wh) {
            $locations[] = explode(',', $wh['long_lat']);
            $vc = array_fill(0, count($productIds), 0);
            foreach ($inventories[$wh['owner_warehouse_id']] ?? [] as $inv) {
                $idx = $productIndex[$inv['product_id']];
                $vc[$idx] = $inv['quantity'];
            }
            $vehicle_capacities[] = $vc;
        }

        foreach ($orders as $o) {
            $d = array_fill(0, count($productIds), 0);
            foreach ($o['order_items'] as $item) {
                $d[$productIndex[$item['product_id']]] = $item['quantity'];
            }
            $demands[] = $d;
            $locations[] = [$o['client_object_latitude'],$o['client_object_longitude']];
        }

        return [
            'locations' => $locations,
            'vehicle_capacities' => $vehicle_capacities,
            'demands' => $demands,
            'numVehicles' => count($vehicle_capacities),
            'depot_indices' => range(0,count($vehicle_capacities)-1)
        ];
    }

    /**
     * Call Flask OR-Tools async endpoint
     * Always returns ['job_id' => 'uuid'] on success
     */
    public function optimizeAsync(array $payload): array
    {
        try {
            $resp = Http::timeout(180)->post("{$this->apiUrl}/ortools/optimize", $payload);

            if ($resp->successful()) {
                $json = $resp->json();
                if (isset($json['job_id'])) {
                    return ['job_id' => $json['job_id']];
                }
                Log::error('OR-Tools response missing job_id', ['resp'=>$json]);
                return ['job_id' => null, 'error'=>'OR-Tools response missing job_id'];
            }

            Log::error('OR-Tools request failed', ['status'=>$resp->status(), 'body'=>$resp->body()]);
            return ['job_id'=>null, 'error'=>'OR-Tools request failed'];

        } catch (\Throwable $e) {
            Log::error('OR-Tools optimize exception', ['error' => $e->getMessage()]);
            return ['job_id'=>null, 'error'=>$e->getMessage()];
        }
    }

    /**
     * Get OR-Tools job status
     */
    public function getJobStatus(string $jobId): array
    {
        try {
            $resp = Http::get("{$this->apiUrl}/ortools/status/{$jobId}");
            if ($resp->successful()) {
                return $resp->json();
            }
            return ['status'=>'error','message'=>$resp->body()];
        } catch (\Throwable $e) {
            Log::error('OR-Tools job status exception', ['error'=>$e->getMessage()]);
            return ['status'=>'error','message'=>$e->getMessage()];
        }
    }
}
