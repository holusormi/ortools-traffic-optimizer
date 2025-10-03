import os
import uuid
import json
import traceback
import math
from concurrent.futures import ThreadPoolExecutor
from flask import Flask, request, jsonify
from flask_cors import CORS
from typing import List, Dict, Tuple
from dataclasses import dataclass

from ortools.constraint_solver import pywrapcp, routing_enums_pb2

app = Flask(__name__)
CORS(app)

JOBS = {}
EXECUTOR = ThreadPoolExecutor(max_workers=8)
JOBS_DIR = "/tmp/ortools_jobs"
os.makedirs(JOBS_DIR, exist_ok=True)


@dataclass
class AssignmentStrategy:
    """Available assignment strategies"""
    ORTOOLS_BALANCED = "ortools_balanced"
    CLOSEST_WITH_INVENTORY = "closest_with_inventory"
    CLOSEST_ANY = "closest_any"
    LEAST_ASSIGNED = "least_assigned_orders"
    LEAST_TOTAL_LOAD = "least_total_load"
    ZONE_BASED = "zone_based"


# ---------------- Persistence ----------------
def job_path(job_id: str) -> str:
    return os.path.join(JOBS_DIR, f"{job_id}.json")


def save_job(job_id: str, data: dict):
    with open(job_path(job_id), "w") as f:
        json.dump(data, f)


def load_job(job_id: str) -> dict | None:
    path = job_path(job_id)
    if os.path.exists(path):
        with open(path) as f:
            return json.load(f)
    return None


def update_job(job_id: str, **kwargs):
    job = JOBS.get(job_id) or load_job(job_id) or {}
    job.update(kwargs)
    JOBS[job_id] = job
    save_job(job_id, job)
    return job


# ---------------- Distance Calculation ----------------
def haversine_distance(lat1, lon1, lat2, lon2):
    """Calculate distance in meters using Haversine formula"""
    R = 6371000  # Earth radius in meters
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    delta_phi = math.radians(lat2 - lat1)
    delta_lambda = math.radians(lon2 - lon1)

    a = math.sin(delta_phi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(delta_lambda / 2) ** 2
    c = 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))

    return int(R * c)


# ---------------- Inventory Checking ----------------
def check_inventory_availability(warehouse: dict, order: dict) -> Tuple[bool, List[dict]]:
    """
    Check if warehouse has sufficient inventory for order
    Returns (can_fulfill, missing_items)
    """
    warehouse_inventory = {item['product_id']: item['quantity'] 
                          for item in warehouse.get('products', [])}
    
    missing_items = []
    for item in order.get('order_items', []):
        product_id = item.get('product_id')
        required_qty = int(item.get('quantity', 0))
        available_qty = warehouse_inventory.get(product_id, 0)
        
        if available_qty < required_qty:
            missing_items.append({
                'product_id': product_id,
                'product_name': item.get('product_name', 'Unknown'),
                'required': required_qty,
                'available': available_qty,
                'shortage': required_qty - available_qty
            })
    
    return len(missing_items) == 0, missing_items


# ---------------- Assignment Strategies ----------------
def assign_closest_with_inventory(warehouses: List[dict], orders: List[dict]) -> dict:
    """Assign orders to closest warehouse that has inventory"""
    assignments = {}
    unassigned = []
    
    for order_idx, order in enumerate(orders):
        order_lat = float(order['client_object_latitude'])
        order_lng = float(order['client_object_longitude'])
        
        best_warehouse = None
        best_distance = float('inf')
        
        for wh_idx, wh in enumerate(warehouses):
            # Check inventory first
            can_fulfill, missing = check_inventory_availability(wh, order)
            if not can_fulfill:
                continue
            
            # Check current load vs capacity
            current_load = wh.get('current_assigned_load', 0)
            if current_load >= wh.get('capacity', 100):
                continue
            
            distance = haversine_distance(
                float(wh['latitude']), float(wh['longitude']),
                order_lat, order_lng
            )
            
            if distance < best_distance:
                best_distance = distance
                best_warehouse = wh_idx
        
        if best_warehouse is not None:
            if best_warehouse not in assignments:
                assignments[best_warehouse] = []
            
            assignments[best_warehouse].append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'distance': best_distance,
                'strategy': 'closest_with_inventory'
            })
            
            # Update warehouse load
            order_demand = sum(int(item.get('quantity', 0)) 
                             for item in order.get('order_items', []))
            warehouses[best_warehouse]['current_assigned_load'] = \
                warehouses[best_warehouse].get('current_assigned_load', 0) + order_demand
        else:
            unassigned.append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'reason': 'no_warehouse_with_inventory'
            })
    
    return {'assignments': assignments, 'unassigned': unassigned}


def assign_closest_any(warehouses: List[dict], orders: List[dict]) -> dict:
    """Assign orders to closest warehouse regardless of inventory"""
    assignments = {}
    unassigned = []
    
    for order_idx, order in enumerate(orders):
        order_lat = float(order['client_object_latitude'])
        order_lng = float(order['client_object_longitude'])
        
        best_warehouse = None
        best_distance = float('inf')
        needs_restock = False
        
        for wh_idx, wh in enumerate(warehouses):
            # Check current load vs capacity
            current_load = wh.get('current_assigned_load', 0)
            if current_load >= wh.get('capacity', 100):
                continue
            
            distance = haversine_distance(
                float(wh['latitude']), float(wh['longitude']),
                order_lat, order_lng
            )
            
            if distance < best_distance:
                best_distance = distance
                best_warehouse = wh_idx
                can_fulfill, missing = check_inventory_availability(wh, order)
                needs_restock = not can_fulfill
        
        if best_warehouse is not None:
            if best_warehouse not in assignments:
                assignments[best_warehouse] = []
            
            assignments[best_warehouse].append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'distance': best_distance,
                'needs_restock': needs_restock,
                'strategy': 'closest_any'
            })
            
            order_demand = sum(int(item.get('quantity', 0)) 
                             for item in order.get('order_items', []))
            warehouses[best_warehouse]['current_assigned_load'] = \
                warehouses[best_warehouse].get('current_assigned_load', 0) + order_demand
        else:
            unassigned.append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'reason': 'all_warehouses_at_capacity'
            })
    
    return {'assignments': assignments, 'unassigned': unassigned}


def assign_least_assigned(warehouses: List[dict], orders: List[dict]) -> dict:
    """Assign orders to warehouse with fewest assigned orders"""
    assignments = {}
    unassigned = []
    
    for order_idx, order in enumerate(orders):
        # Find warehouse with least assigned orders
        best_warehouse = None
        min_assigned = float('inf')
        
        for wh_idx, wh in enumerate(warehouses):
            # Check capacity
            current_load = wh.get('current_assigned_load', 0)
            if current_load >= wh.get('capacity', 100):
                continue
            
            # Count current assignments including pre-assigned
            current_count = len(assignments.get(wh_idx, [])) + \
                          wh.get('pre_assigned_count', 0)
            
            if current_count < min_assigned:
                # Check inventory
                can_fulfill, _ = check_inventory_availability(wh, order)
                if can_fulfill:
                    min_assigned = current_count
                    best_warehouse = wh_idx
        
        if best_warehouse is not None:
            if best_warehouse not in assignments:
                assignments[best_warehouse] = []
            
            assignments[best_warehouse].append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'strategy': 'least_assigned'
            })
            
            order_demand = sum(int(item.get('quantity', 0)) 
                             for item in order.get('order_items', []))
            warehouses[best_warehouse]['current_assigned_load'] = \
                warehouses[best_warehouse].get('current_assigned_load', 0) + order_demand
        else:
            unassigned.append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'reason': 'no_warehouse_available'
            })
    
    return {'assignments': assignments, 'unassigned': unassigned}


def assign_least_total_load(warehouses: List[dict], orders: List[dict]) -> dict:
    """Assign orders to warehouse with lowest total load"""
    assignments = {}
    unassigned = []
    
    for order_idx, order in enumerate(orders):
        order_demand = sum(int(item.get('quantity', 0)) 
                         for item in order.get('order_items', []))
        
        best_warehouse = None
        min_load = float('inf')
        
        for wh_idx, wh in enumerate(warehouses):
            current_load = wh.get('current_assigned_load', 0)
            capacity = wh.get('capacity', 100)
            
            # Check if adding this order would exceed capacity
            if current_load + order_demand > capacity:
                continue
            
            # Check inventory
            can_fulfill, _ = check_inventory_availability(wh, order)
            if not can_fulfill:
                continue
            
            if current_load < min_load:
                min_load = current_load
                best_warehouse = wh_idx
        
        if best_warehouse is not None:
            if best_warehouse not in assignments:
                assignments[best_warehouse] = []
            
            assignments[best_warehouse].append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'strategy': 'least_total_load'
            })
            
            warehouses[best_warehouse]['current_assigned_load'] = \
                warehouses[best_warehouse].get('current_assigned_load', 0) + order_demand
        else:
            unassigned.append({
                'order_index': order_idx,
                'order_id': order.get('order_id'),
                'reason': 'insufficient_capacity_or_inventory'
            })
    
    return {'assignments': assignments, 'unassigned': unassigned}


# ---------------- Data Preparation ----------------
def prepare_data(payload: dict, strategy: str = AssignmentStrategy.ORTOOLS_BALANCED):
    """Transform warehouses + orders into routing structures"""
    warehouses = payload.get("warehouses", [])
    orders = payload.get("orders", [])
    
    if not warehouses:
        raise ValueError("No warehouses provided")
    if not orders:
        raise ValueError("No orders provided")
    
    print(f"Processing {len(warehouses)} warehouses and {len(orders)} orders")
    print(f"Using strategy: {strategy}")
    
    # Initialize current load from pre-assigned orders
    for wh in warehouses:
        wh['current_assigned_load'] = wh.get('pre_assigned_load', 0)
        wh['pre_assigned_count'] = wh.get('pre_assigned_count', 0)
    
    # Apply greedy strategy if requested
    if strategy != AssignmentStrategy.ORTOOLS_BALANCED:
        result = None
        
        if strategy == AssignmentStrategy.CLOSEST_WITH_INVENTORY:
            result = assign_closest_with_inventory(warehouses, orders)
        elif strategy == AssignmentStrategy.CLOSEST_ANY:
            result = assign_closest_any(warehouses, orders)
        elif strategy == AssignmentStrategy.LEAST_ASSIGNED:
            result = assign_least_assigned(warehouses, orders)
        elif strategy == AssignmentStrategy.LEAST_TOTAL_LOAD:
            result = assign_least_total_load(warehouses, orders)
        
        if result:
            return format_greedy_result(warehouses, orders, result, strategy)
    
    # Continue with OR-Tools for balanced strategy
    return prepare_ortools_data(warehouses, orders)


def format_greedy_result(warehouses, orders, result, strategy):
    """Format greedy assignment results"""
    route_details = []
    
    for wh_idx, wh in enumerate(warehouses):
        assigned_orders = result['assignments'].get(wh_idx, [])
        
        if not assigned_orders:
            continue
        
        route = []
        total_load = 0
        total_distance = 0
        
        # Add warehouse start
        route.append({
            'location_index': wh_idx,
            'load': 0,
            'demand': 0,
            'location_info': {
                'type': 'warehouse',
                'id': wh.get('id'),
                'name': wh.get('name'),
                'vehicle_name': wh.get('vehicle_name'),
                'driver_name': wh.get('driver_name')
            }
        })
        
        # Add assigned orders
        for assignment in assigned_orders:
            order_idx = assignment['order_index']
            order = orders[order_idx]
            
            order_demand = sum(int(item.get('quantity', 0)) 
                             for item in order.get('order_items', []))
            total_load += order_demand
            total_distance += assignment.get('distance', 0)
            
            route.append({
                'location_index': len(warehouses) + order_idx,
                'load': total_load,
                'demand': order_demand,
                'needs_restock': assignment.get('needs_restock', False),
                'location_info': {
                    'type': 'order',
                    'order_id': order.get('order_id'),
                    'order_no': order.get('order_no'),
                    'client_name': order.get('client_object_name'),
                    'client_address': order.get('client_object_address'),
                    'client_phone': order.get('client_phone')
                }
            })
        
        # Add warehouse end
        route.append({
            'location_index': wh_idx,
            'load': total_load,
            'demand': 0,
            'location_info': {
                'type': 'warehouse',
                'id': wh.get('id'),
                'name': wh.get('name')
            }
        })
        
        route_details.append({
            'vehicle_id': wh_idx,
            'route': route,
            'total_distance': total_distance,
            'total_distance_km': round(total_distance / 1000, 2),
            'total_load': total_load,
            'stops_count': len(assigned_orders),
            'warehouse_info': {
                'id': wh.get('id'),
                'name': wh.get('name'),
                'vehicle_name': wh.get('vehicle_name'),
                'driver_name': wh.get('driver_name')
            },
            'strategy_used': strategy
        })
    
    return {
        'route_details': route_details,
        'unassigned_orders': result['unassigned'],
        'strategy': strategy,
        'meta': {
            'warehouses_count': len(warehouses),
            'orders_count': len(orders),
            'assigned_count': sum(len(r['route']) - 2 for r in route_details),
            'unassigned_count': len(result['unassigned'])
        }
    }


def prepare_ortools_data(warehouses, orders):
    """Prepare data for OR-Tools optimization"""
    # ... (Keep existing prepare_data logic for OR-Tools)
    # This is your existing preparation code
    pass


# ---------------- Flask Endpoints ----------------
@app.route("/ortools/optimize", methods=["POST"])
def optimize():
    try:
        payload = request.get_json(force=True)
        
        if not payload.get("warehouses"):
            return jsonify({"error": "Missing 'warehouses' in payload"}), 400
        if not payload.get("orders"):
            return jsonify({"error": "Missing 'orders' in payload"}), 400
        
        strategy = payload.get("strategy", AssignmentStrategy.ORTOOLS_BALANCED)
        
        # Validate strategy
        valid_strategies = [
            AssignmentStrategy.ORTOOLS_BALANCED,
            AssignmentStrategy.CLOSEST_WITH_INVENTORY,
            AssignmentStrategy.CLOSEST_ANY,
            AssignmentStrategy.LEAST_ASSIGNED,
            AssignmentStrategy.LEAST_TOTAL_LOAD
        ]
        
        if strategy not in valid_strategies:
            return jsonify({"error": f"Invalid strategy. Must be one of: {valid_strategies}"}), 400
        
        job_id = str(uuid.uuid4())
        JOBS[job_id] = {
            "status": "running",
            "progress": 0,
            "result": None,
            "error": None,
            "payload": payload,
            "strategy": strategy
        }
        save_job(job_id, JOBS[job_id])
        
        EXECUTOR.submit(solve_routing_job, job_id, payload, strategy)
        
        return jsonify({"job_id": job_id, "status": "running", "strategy": strategy})
    
    except Exception as e:
        print(f"ERROR in /optimize: {e}")
        return jsonify({"error": str(e)}), 500


@app.route("/ortools/strategies", methods=["GET"])
def list_strategies():
    """List available assignment strategies"""
    return jsonify({
        "strategies": [
            {
                "id": AssignmentStrategy.ORTOOLS_BALANCED,
                "name": "OR-Tools Balanced",
                "description": "Uses Google OR-Tools to optimize routes with balanced load distribution"
            },
            {
                "id": AssignmentStrategy.CLOSEST_WITH_INVENTORY,
                "name": "Closest Driver with Inventory",
                "description": "Assigns orders to nearest driver who has required inventory"
            },
            {
                "id": AssignmentStrategy.CLOSEST_ANY,
                "name": "Closest Driver (Any)",
                "description": "Assigns orders to nearest driver regardless of inventory (may need restocking)"
            },
            {
                "id": AssignmentStrategy.LEAST_ASSIGNED,
                "name": "Least Assigned Orders",
                "description": "Assigns orders to driver with fewest current assignments"
            },
            {
                "id": AssignmentStrategy.LEAST_TOTAL_LOAD,
                "name": "Least Total Load",
                "description": "Assigns orders to driver with lowest current load"
            }
        ]
    })


def solve_routing_job(job_id: str, payload: dict, strategy: str):
    try:
        update_job(job_id, status="processing", progress=10)
        
        prepared = prepare_data(payload, strategy)
        
        update_job(
            job_id,
            status="done",
            progress=100,
            result=prepared,
            error=None
        )
        
    except Exception as e:
        tb = traceback.format_exc()
        print(f"ERROR in job {job_id}: {e}\n{tb}")
        update_job(
            job_id,
            status="error",
            progress=100,
            result=None,
            error=f"{str(e)}\n{tb}"
        )


@app.route("/ortools/status/<job_id>", methods=["GET"])
def status(job_id):
    job = JOBS.get(job_id) or load_job(job_id)
    if not job:
        return jsonify({"status": "error", "error": "Job not found"}), 404
    
    return jsonify({
        "status": job.get("status", "unknown"),
        "progress": job.get("progress", 0),
        "result": job.get("result"),
        "error": job.get("error"),
        "strategy": job.get("strategy")
    })


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "healthy",
        "jobs_count": len(JOBS),
        "active_jobs": len([j for j in JOBS.values() if j.get("status") in ["running", "processing"]])
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True, threaded=True)
