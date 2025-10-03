<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\RoutePlannerService;
use App\Models\Orders\Order;
use App\Models\WarehouseInventories\WarehouseInventory;
use App\Models\OwnerBases\OwnerBase;

class DispatchersController extends Controller
{
    protected RoutePlannerService $planner;

    public function __construct(RoutePlannerService $planner)
    {
        $this->planner = $planner;
    }

    public function index()
    {
        //return $this->getOrdersDataForOptimization();
        return view('backend.dispatchers.index');
    }

    public function dispatchNow(Request $request)
    {
        $data = $this->planner->prepareDataForOptimization();

        $result = $this->planner->optimizeAsync($data);

        return response()->json([
            'job_id' => $result['job_id'] ?? null,
            'status' => $result['job_id'] ? 'running' : 'error',
            'error' => $result['error'] ?? null
        ]);
    }

    public function pollJobStatus(string $jobId)
    {
        $status = $this->planner->getJobStatus($jobId);

        return response()->json([
            'status' => $status['status'] ?? 'error',
            'progress' => $status['progress'] ?? 0,
            'result' => $status['result'] ?? null,
            'error' => $status['error'] ?? null
        ]);
    }

    public function reoptimize(Request $request)
    {
        $locations = $request->input('locations', []);
        if (!$locations) {
            return response()->json(['error' => 'No locations provided'], 422);
        }

        $result = $this->planner->optimizeAsync(['locations' => $locations]);

        if (empty($result['job_id'])) {
            return response()->json([
                'job_id' => null,
                'error' => $result['error'] ?? 'Unknown error'
            ], 500);
        }

        return response()->json(['job_id' => $result['job_id']]);
    }

    /**
     * Collect all data needed for OR-Tools optimization
     * Returns data with CONSISTENT coordinate format: [latitude, longitude]
     */
    public function getOrdersDataForOptimization(): array
    {
        $ownerBases = OwnerBase::where('status', 1)
            ->userBase()
            ->has('inUseMobileWarehouse')
            ->with('inUseMobileWarehouse')
            ->get();

        $warehouses = [];
        $whIds = [];

        foreach ($ownerBases as $ownerBase) {
            foreach ($ownerBase->inUseMobileWarehouse as $warehouse) {
                $whIds[] = $warehouse->id;
                
                // FIXED: Consistent [lat, lng] format
                $lat = $warehouse->getLatitudeAttribute();
                $lng = $warehouse->getLongitudeAttribute();
                
                $warehouses[] = [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'long_lat' => "{$lat},{$lng}"  // FIXED: lat,lng format
                ];
            }
        }

        $inventoriesData = WarehouseInventory::getInventory([
            'owner_warehouse_ids' => $whIds,
            'no_key_group' => 1,
            'latest_format' => 1,
            'is_inventorable' => 1
        ]);

        $inventories = [];
        foreach ($inventoriesData as $i) {
            if (!isset($inventories[$i->owner_warehouse_id])) {
                $inventories[$i->owner_warehouse_id] = [];
            }
            $inventories[$i->owner_warehouse_id][] = [
                'product_id' => (int)$i->product_id,
                'quantity' => (int)$i->total_qty  // Force integer
            ];
        }

        $orders = Order::with('clientObject', 'orderDetails')
            ->where('is_completed', 0)
            ->whereDate('delivery_datetime', now())
            ->get()
            ->map(function($o) {
                $lat = $o->clientObject->geo_coordinates->latitude ?? 0;
                $lng = $o->clientObject->geo_coordinates->longitude ?? 0;
                
                return [
                    'order_id' => $o->id,
                    'client_object_name' => $o->clientObject->name ?? 'Unknown',
                    'client_object_address' => $o->clientObject->address ?? '',
                    'client_object_latitude' => (float)$lat,
                    'client_object_longitude' => (float)$lng,
                    'order_items' => $o->orderDetails->map(fn($d) => [
                        'product_id' => $d->product_id,
                        'product_name' => $d->product->name,
                        'quantity' => $d->quantity
                    ])->toArray()
                ];
            })->toArray();

        return compact('warehouses', 'orders', 'inventories');
    }
}
