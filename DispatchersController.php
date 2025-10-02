<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\RoutePlannerService;
use App\Jobs\OptimizeRoutesJob;
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

    /**
     * Show dispatcher page
     */
    public function index()
    {
             //  return          $data = $this->getOrdersDataForOptimization();

        return          $data = $this->planner->prepareDataForOptimization();


        return view('backend.dispatchers.index');
    }

    /**
     * Dispatch optimization immediately and return OR-Tools job_id
     */
    public function dispatchNow(Request $request)
    {
         $data = $this->planner->prepareDataForOptimization();

        // Call OR-Tools async endpoint directly
        $result = $this->planner->optimizeAsync($data);

        return response()->json([
            'job_id' => $result['job_id'] ?? null,
            'status' => $result['job_id'] ? 'running' : 'error',
            'error' => $result['error'] ?? null
        ]);
    }

    /**
     * Poll job status from OR-Tools
     */
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

    /**
     * Re-optimize with selected locations
     */
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
     */
      public function OLDgetOrdersDataForOptimization($data=[])
    {
        $RandomLocations=['27.8258075,43.5384881','27.5856827,43.8860837','28.0841928,43.7255355  ','27.6650749,43.5324012',
        '28.0174777,43.8570493','27.7695562,43.7214547','27.9063477,43.5041448','27.5940780,43.5839136','27.6684079,43.8258939',
        '27.9317807,43.5952642']; //For test this will be replaced by warehouse real time locations
 $warehouseLocations=[];
             $ownerBases = OwnerBase::where('status',1)->userBase()->has('inUseMobileWarehouse')->with('inUseMobileWarehouse')->get(); 
           $whIds=[];
        foreach ($ownerBases as $ownerBase) {
            foreach($ownerBase->inUseMobileWarehouse as $k=>$warehouse){
                $whIds[] = $warehouse->id;  
                $warehouseLocations[$warehouse->id] = ['vehicle_no'=>$warehouse->name,'owner_warehouse_id'=>$warehouse->id,'long_lat'=>$RandomLocations[$k+rand(0,count($RandomLocations)-4)]];            
            }
         }
           $invdata['owner_warehouse_ids'] = $whIds;
          $invdata['no_key_group'] = $invdata['latest_format'] = $invdata['is_inventorable'] =1; 
            $getInventories= WarehouseInventory::getInventory($invdata);  
            $inventories=[];
            foreach($getInventories as $m){
                $products=['product_id'=>$m->product_id,'quantity'=>$m->total_qty,'owner_warehouse_id'=>$m->owner_warehouse_id];
                $inventories[$m->owner_warehouse_id][]=$products; 
            }     
       
        $date=date('Y-m-d');
        $whereRaw ="orders.is_completed =0 AND DATE(orders.delivery_datetime)='$date' ";
       
              
        $orders = Order::whereRaw($whereRaw)
    ->orderBy('orders.delivery_datetime', 'asc')
    ->selectRaw("
        orders.id as order_id, 
        (orders.order_sum + orders.discount_sum + orders.returned_sum) as order_total_sum,
        client_objects.name as client_object_name,
        client_objects.address as client_object_address,
        client_objects.company_id,
        client_objects.area_id,
        client_objects.phones as client_phones,
        JSON_EXTRACT(client_objects.geo_coordinates, '$.latitude') AS client_object_latitude,
        JSON_EXTRACT(client_objects.geo_coordinates, '$.longitude') AS client_object_longitude,
        DATE_FORMAT(orders.delivery_datetime,'%d.%m.%Y') as order_delivery_date,
        od_s.name as order_status_name,
        COALESCE(
            JSON_ARRAYAGG(
                JSON_OBJECT(
                    'product_id', order_details.product_id,
                    'quantity', order_details.quantity
                 )
            ), JSON_ARRAY()
        ) as order_items
    ")
    ->leftJoin('nomenclatures as od_s', 'od_s.id', '=', 'orders.n_status_id')
    ->leftJoin('client_objects', 'client_objects.id', '=', 'orders.client_object_id')
    ->leftJoin('companies', 'companies.id', '=', 'client_objects.company_id')
    ->leftJoin('order_details', 'order_details.order_id', '=', 'orders.id')
    ->groupBy('orders.id')->get();
    $locations=[];
         /* foreach($orders as $m){
                 $locations[]=$m->client_object_longitude.','.$m->client_object_latitude; 
            }*/
        return [compact('warehouseLocations','orders','inventories')];
    }
    public function getOrdersDataForOptimization(): array
    {
           $RandomLocations=['27.8258075,43.5384881','27.5856827,43.8860837','28.0841928,43.7255355  ','27.6650749,43.5324012',
        '28.0174777,43.8570493','27.7695562,43.7214547','27.9063477,43.5041448','27.5940780,43.5839136','27.6684079,43.8258939',
        '27.9317807,43.5952642']; //For test this will be replaced by warehouse real time locations
        $ownerBases = OwnerBase::where('status', 1)
            ->userBase()
            ->has('inUseMobileWarehouse')
            ->with('inUseMobileWarehouse')
            ->get();

        $warehouseLocations = [];
        $whIds = [];
        // $warehouse->latitude . ',' . $warehouse->longitude
        foreach ($ownerBases as $ownerBase) {
            foreach ($ownerBase->inUseMobileWarehouse as $k=> $warehouse) {
                $whIds[] = $warehouse->id;
                $warehouseLocations[$warehouse->id] = [
                    'vehicle_no' => $warehouse->name,
                    'owner_warehouse_id' => $warehouse->id,
                    'long_lat' => $warehouse->getLongitudeAttribute(). ',' .$warehouse->getLatitudeAttribute()  
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
            $inventories[$i->owner_warehouse_id][] = [
                'product_id' => $i->product_id,
                'quantity' => $i->total_qty,
                'owner_warehouse_id' => $i->owner_warehouse_id
            ];
        }

        $orders = Order::with('clientObject','orderDetails')
            ->where('is_completed', 0)
            ->whereDate('delivery_datetime', now())
            ->get()
            ->map(function($o){
                return [
                    'order_id' => $o->id,
                    'client_object_name' => $o->clientObject->name,
                    'client_object_address' => $o->clientObject->address,
                    'client_object_latitude' => $o->clientObject->geo_coordinates->latitude ?? 0,
                    'client_object_longitude' => $o->clientObject->geo_coordinates->longitude ?? 0,
                    'order_items' => $o->orderDetails->map(fn($d) => [
                        'product_id' => $d->product_id,
                        'quantity' => $d->quantity
                    ])->toArray()
                ];
            })->toArray();

        return compact('warehouseLocations','orders','inventories');
    }
}
