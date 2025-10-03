<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\RoutePlannerService;
use App\Models\Orders\Order;
use App\Models\WarehouseInventories\WarehouseInventory;
use App\Models\OwnerBases\OwnerBase;
use App\Models\Vehicles\Vehicle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        $stats = $this->getDashboardStats();
        return view('backend.dispatchers.index', compact('stats'));
    }

    /**
     * Get real-time dashboard statistics
     */
    public function getDashboardStats()
    {
        $today = now()->format('Y-m-d');
        
        return [
            'pending_orders' => Order::where('is_completed', 0)
                ->whereDate('delivery_datetime', $today)
                ->count(),
            'completed_orders' => Order::where('is_completed', 1)
                ->whereDate('delivery_datetime', $today)
                ->count(),
            'active_vehicles' => OwnerBase::where('status', 1)
                ->userBase()
                ->has('inUseMobileWarehouse')
                ->withCount('inUseMobileWarehouse')
                ->first()
                ->in_use_mobile_warehouse_count ?? 0,
            'total_revenue' => Order::where('is_completed', 1)
                ->whereDate('delivery_datetime', $today)
                ->sum('order_sum'),
        ];
    }

    /**
     * Dispatch optimization with enhanced data
     */
    public function dispatchNow(Request $request)
    {
        try {
            $data = $this->planner->prepareDataForOptimization();

            // Validate we have data to optimize
            if (empty($data['warehouses']) || empty($data['orders'])) {
                return response()->json([
                    'job_id' => null,
                    'status' => 'error',
                    'error' => 'No vehicles or orders available for optimization'
                ]);
            }

            $result = $this->planner->optimizeAsync($data);

            return response()->json([
                'job_id' => $result['job_id'] ?? null,
                'status' => $result['job_id'] ? 'running' : 'error',
                'error' => $result['error'] ?? null
            ]);
        } catch (\Exception $e) {
            Log::error('Dispatch error', ['error' => $e->getMessage()]);
            return response()->json([
                'job_id' => null,
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Poll job status with enriched data
     */
    public function pollJobStatus(string $jobId)
    {
        $status = $this->planner->getJobStatus($jobId);

        // Enrich with order and warehouse details
        if ($status['status'] === 'done' && isset($status['prepared'])) {
            $status = $this->enrichResultWithDetails($status);
        }

        return response()->json([
            'status' => $status['status'] ?? 'error',
            'progress' => $status['progress'] ?? 0,
            'result' => $status['result'] ?? null,
            'prepared' => $status['prepared'] ?? null,
            'error' => $status['error'] ?? null
        ]);
    }

    /**
     * Enrich optimization result with real order and warehouse data
     */
    protected function enrichResultWithDetails(array $status): array
    {
        $data = $this->getOrdersDataForOptimization();
        $warehouses = $data['warehouses'];
        $orders = $data['orders'];

        // Add warehouse details
        $status['warehouses_details'] = $warehouses;

        // Add order details
        $status['orders_details'] = $orders;

        return $status;
    }

    /**
     * Manual route assignment
     */
    public function assignOrder(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'vehicle_id' => 'required|integer'
        ]);

        try {
            $order = Order::findOrFail($request->order_id);
            
            // Update order with vehicle assignment
            $order->update([
                'data' => array_merge($order->data ?? [], [
                    'assigned_vehicle_id' => $request->vehicle_id,
                    'assigned_at' => now()->toISOString()
                ])
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Complete delivery
     */
    public function completeDelivery(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'signature' => 'nullable|string',
            'photo' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        try {
            $order = Order::findOrFail($request->order_id);
            
            $order->update([
                'is_completed' => 1,
                'delivered_datetime' => now(),
                'data' => array_merge($order->data ?? [], [
                    'delivery_signature' => $request->signature,
                    'delivery_photo' => $request->photo,
                    'delivery_notes' => $request->notes,
                    'completed_at' => now()->toISOString()
                ])
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get orders data with comprehensive details
     */
    public function getOrdersDataForOptimization(): array
    {
        $ownerBases = OwnerBase::where('status', 1)
            ->userBase()
            ->has('inUseMobileWarehouse')
            ->with(['inUseMobileWarehouse.vehicle', 'inUseMobileWarehouse.inuseUser'])
            ->get();

        $warehouses = [];
        $whIds = [];
 
        foreach ($ownerBases as $ownerBase) {
            foreach ($ownerBase->inUseMobileWarehouse as $warehouse) {
                $whIds[] = $warehouse->id;
                
                $lat = $warehouse->getLatitudeAttribute();
                $lng = $warehouse->getLongitudeAttribute();
                
                // Get vehicle details
                $vehicle = $warehouse->vehicle;
                $driver = $warehouse->inuseUser;
                
                $warehouses[] = [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'latitude' => (float)$lat,
                    'longitude' => (float)$lng,
                    'long_lat' => "{$lat},{$lng}",
                    'vehicle_name' => $vehicle->reg_no ?? 'N/A',
                    'vehicle_id' => $vehicle->id ?? null,
                    'driver_name' => $driver->name ?? 'Unassigned',
                    'driver_phone' => $driver->phone ?? null,
                ];
            }
        }
       
        // Get inventory with capacity calculation
        $inventoriesData = WarehouseInventory::getInventory([
            'owner_warehouse_ids' => $whIds,
            'no_key_group' => 1,
            'latest_format' => 1,
            'is_inventorable' => 1
        ]);

        $inventories = [];
        $warehouseCapacities = [];
        
        foreach ($inventoriesData as $i) {
            $whId = $i->owner_warehouse_id;
            
            if (!isset($inventories[$whId])) {
                $inventories[$whId] = [];
                $warehouseCapacities[$whId] = 0;
            }
            
            $qty = (int)$i->total_qty;
            $inventories[$whId][] = [
                'product_id' => (int)$i->product_id,
                'quantity' => $qty
            ];
            $warehouseCapacities[$whId] += $qty;
        }

        // Add capacity to warehouses
        foreach ($warehouses as &$wh) {
            $wh['capacity'] = $warehouseCapacities[$wh['id']] ?? 100;
            $wh['products'] = $inventories[$wh['id']] ?? [];
        }
        unset($wh);

        // Get orders with full details
           $orders = Order::with(['clientObject', 'orderDetails.product'])
            ->where('is_completed', 0)
            ->whereDate('delivery_datetime', now())
            ->orderBy('delivery_datetime')
            ->get()
            ->filter(function($o) {
                // Filter out orders with invalid coordinates
                $lat = $o->clientObject->geo_coordinates->latitude ?? 0;
                $lng = $o->clientObject->geo_coordinates->longitude ?? 0;
                return $lat != 0 && $lng != 0;
            })
            ->map(function($o) {
                $lat = $o->clientObject->geo_coordinates->latitude ?? 0;
                $lng = $o->clientObject->geo_coordinates->longitude ?? 0;
                
                // Safely format delivery datetime
                $deliveryDatetime = $o->delivery_datetime;
                if (is_string($deliveryDatetime)) {
                    try {
                        $deliveryDatetime = \Carbon\Carbon::parse($deliveryDatetime);
                    } catch (\Exception $e) {
                        $deliveryDatetime = now();
                    }
                }
                
                return [
                    'order_id' => $o->id,
                    'order_no' => $o->no,
                    'client_object_name' => $o->clientObject->name ?? 'Unknown',
                    'client_object_address' => $o->clientObject->address ?? '',
                    'client_phone' => $o->clientObject->phones ?? '',
                    'client_object_latitude' => (float)$lat,
                    'client_object_longitude' => (float)$lng,
                    'delivery_datetime' => $deliveryDatetime->format('Y-m-d H:i'),
                    'order_sum' => (float)$o->order_sum,
                    'order_items' => $o->orderDetails->map(fn($d) => [
                        'product_id' => (int)$d->product_id,
                        'product_name' => $d->product->name ?? 'Unknown',
                        'quantity' => (int)$d->quantity,
                        'amount' => (float)$d->amount
                    ])->toArray(),
                    'total_items' => $o->orderDetails->sum('quantity'),
                    'priority' => $this->calculateOrderPriority($o)
                ];
            })
            ->values()
            ->toArray();

        return compact('warehouses', 'orders', 'inventories');
    }

    /**
     * Calculate order priority based on various factors
     */
    protected function calculateOrderPriority($order): int
    {
        $priority = 5; // Default priority

        // Higher priority for earlier delivery times
        try {
            $deliveryTime = $order->delivery_datetime;
            
            // Convert to Carbon if it's a string
            if (is_string($deliveryTime)) {
                $deliveryTime = \Carbon\Carbon::parse($deliveryTime);
            }
            
            $deliveryHour = $deliveryTime->hour;
            
            if ($deliveryHour < 10) $priority += 3;
            elseif ($deliveryHour < 14) $priority += 1;
        } catch (\Exception $e) {
            // If date parsing fails, use default priority
            Log::warning('Failed to parse delivery datetime', [
                'order_id' => $order->id,
                'delivery_datetime' => $order->delivery_datetime
            ]);
        }

        // Higher priority for larger orders
        $orderSum = (float)$order->order_sum;
        if ($orderSum > 1000) $priority += 2;
        elseif ($orderSum > 500) $priority += 1;

        return min($priority, 10);
    }
}
