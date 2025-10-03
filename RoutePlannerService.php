<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RoutePlannerService
{
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('settings.uapp_api.maps.or_tools_url', 'https://api.uapp.bg/ortools');
    }

    /**
     * Prepare data for optimization with intelligent vehicle-order matching
     */
    public function prepareDataForOptimization(): array
    {
        $data = app()->make('App\Http\Controllers\Backend\DispatchersController')
            ->getOrdersDataForOptimization();

        $warehouses = $data['warehouses'] ?? [];
        $orders = $data['orders'] ?? [];
        $inventories = $data['inventories'] ?? [];

        if (empty($warehouses) || empty($orders)) {
            throw new \Exception('No warehouses or orders available');
        }

        // Match orders to warehouses based on inventory availability
        $matchedOrders = $this->matchOrdersToWarehouses($orders, $warehouses, $inventories);

        Log::info('Route Planner Data Prepared', [
            'warehouses_count' => count($warehouses),
            'orders_count' => count($orders),
            'matched_orders' => count($matchedOrders),
            'sample_warehouse' => $warehouses[0] ?? null,
        ]);

        return [
            'warehouses' => $warehouses,
            'orders' => $orders,
            'inventories' => $inventories,
            'matched_orders' => $matchedOrders
        ];
    }

    /**
     * Match orders to warehouses based on inventory and proximity
     */
    protected function matchOrdersToWarehouses(array $orders, array $warehouses, array $inventories): array
    {
        $matched = [];

        foreach ($orders as $orderIdx => $order) {
            $orderProducts = collect($order['order_items'])->pluck('quantity', 'product_id')->toArray();
            $bestMatch = null;
            $bestScore = -1;

            foreach ($warehouses as $whIdx => $wh) {
                $whProducts = collect($inventories[$wh['id']] ?? [])->pluck('quantity', 'product_id')->toArray();
                
                // Check if warehouse can fulfill order
                $canFulfill = true;
                $inventoryScore = 0;
                
                foreach ($orderProducts as $productId => $qty) {
                    if (!isset($whProducts[$productId]) || $whProducts[$productId] < $qty) {
                        $canFulfill = false;
                        break;
                    }
                    $inventoryScore += $whProducts[$productId];
                }

                if (!$canFulfill) continue;

                // Calculate distance score (inverse - closer is better)
                $distance = $this->calculateDistance(
                    $wh['latitude'], $wh['longitude'],
                    $order['client_object_latitude'], $order['client_object_longitude']
                );
                
                $distanceScore = 1000 / max($distance, 1);
                $totalScore = $distanceScore + ($inventoryScore * 0.1);

                if ($totalScore > $bestScore) {
                    $bestScore = $totalScore;
                    $bestMatch = $whIdx;
                }
            }

            $matched[$orderIdx] = [
                'order_index' => $orderIdx,
                'warehouse_index' => $bestMatch,
                'score' => $bestScore
            ];
        }

        return $matched;
    }

    /**
     * Calculate Haversine distance in meters
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Call OR-Tools async endpoint with validation
     */
    public function optimizeAsync(array $payload): array
    {
        try {
            // Validate payload
            $validation = $this->validatePayload($payload);
            if (!$validation['valid']) {
                Log::error('Payload validation failed', $validation);
                return [
                    'job_id' => null,
                    'error' => 'Validation failed: ' . implode(', ', $validation['issues'])
                ];
            }

            Log::info('Sending to OR-Tools', [
                'url' => "{$this->apiUrl}/ortools/optimize",
                'warehouses' => count($payload['warehouses'] ?? []),
                'orders' => count($payload['orders'] ?? [])
            ]);

            $resp = Http::timeout(180)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->apiUrl}/ortools/optimize", $payload);

            if ($resp->successful()) {
                $json = $resp->json();

                if (isset($json['job_id'])) {
                    Log::info('OR-Tools job created', ['job_id' => $json['job_id']]);
                    
                    // Cache job metadata
                    Cache::put("ortools_job_{$json['job_id']}", [
                        'created_at' => now(),
                        'warehouses' => count($payload['warehouses']),
                        'orders' => count($payload['orders'])
                    ], 3600);
                    
                    return ['job_id' => $json['job_id']];
                }

                Log::error('OR-Tools response missing job_id', ['response' => $json]);
                return ['job_id' => null, 'error' => 'Response missing job_id'];
            }

            $errorBody = $resp->body();
            Log::error('OR-Tools request failed', [
                'status' => $resp->status(),
                'body' => $errorBody
            ]);

            return ['job_id' => null, 'error' => "Request failed: {$errorBody}"];

        } catch (\Throwable $e) {
            Log::error('OR-Tools optimize exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return ['job_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get job status
     */
    public function getJobStatus(string $jobId): array
    {
        try {
            $resp = Http::timeout(10)->get("{$this->apiUrl}/ortools/status/{$jobId}");

            if ($resp->successful()) {
                $result = $resp->json();
                
                // Add cached metadata
                $metadata = Cache::get("ortools_job_{$jobId}");
                if ($metadata) {
                    $result['metadata'] = $metadata;
                }
                
                return $result;
            }

            Log::warning('OR-Tools status check failed', [
                'job_id' => $jobId,
                'status' => $resp->status()
            ]);

            return ['status' => 'error', 'message' => $resp->body()];

        } catch (\Throwable $e) {
            Log::error('OR-Tools job status exception', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Validate payload structure
     */
    protected function validatePayload(array $payload): array
    {
        $issues = [];
        $warnings = [];

        if (empty($payload['warehouses'])) {
            $issues[] = 'No warehouses provided';
        } else {
            foreach ($payload['warehouses'] as $idx => $wh) {
                if (!isset($wh['latitude']) || !isset($wh['longitude'])) {
                    $issues[] = "Warehouse {$idx}: missing coordinates";
                }
            }
        }

        if (empty($payload['orders'])) {
            $issues[] = 'No orders provided';
        } else {
            foreach ($payload['orders'] as $idx => $order) {
                if (!isset($order['client_object_latitude']) || !isset($order['client_object_longitude'])) {
                    $issues[] = "Order {$idx}: missing coordinates";
                }

                if (empty($order['order_items'])) {
                    $warnings[] = "Order {$idx}: no items";
                }
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings
        ];
    }
}
