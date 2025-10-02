import os
import uuid
import json
import traceback
import threading
from concurrent.futures import ThreadPoolExecutor
from flask import Flask, request, jsonify

from ortools.constraint_solver import pywrapcp, routing_enums_pb2

app = Flask(__name__)

# In-memory job store + file persistence
JOBS = {}
EXECUTOR = ThreadPoolExecutor(max_workers=4)
JOBS_DIR = "/tmp/ortools_jobs"
os.makedirs(JOBS_DIR, exist_ok=True)


# ---------------- Persistence Helpers ----------------
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


# ---------------- Data Prep ----------------
def prepare_data(payload: dict):
    """Transform warehouses + orders into OR-Tools structures"""
    warehouses = payload.get("warehouses", [])
    orders = payload.get("orders", [])
    inventories = payload.get("inventories", {})

    locations = []
    depot_indices = []

    # Add depots (warehouses/vehicles start positions)
    for idx, wh in enumerate(warehouses):
        lat, lng = map(float, wh["long_lat"].split(","))
        locations.append((lat, lng))
        depot_indices.append(idx)

    # Add orders
    for o in orders:
        lat, lng = float(o["client_object_latitude"]), float(o["client_object_longitude"])
        locations.append((lat, lng))

    distance_matrix = []
    for i, (lat1, lng1) in enumerate(locations):
        row = []
        for j, (lat2, lng2) in enumerate(locations):
            row.append(int(abs(lat1 - lat2) * 1000 + abs(lng1 - lng2) * 1000))
        distance_matrix.append(row)

    demands = [0] * len(locations)
    for i, o in enumerate(orders):
        total_qty = sum(d["quantity"] for d in o["order_items"])
        demands[len(warehouses) + i] = total_qty

    capacities = [100 for _ in warehouses]  # default capacity

    return {
        "distance_matrix": distance_matrix,
        "demands": demands,
        "capacities": capacities,
        "depot_indices": depot_indices,
        "meta": {
            "orders_count": len(orders),
            "warehouses_count": len(warehouses)
        }
    }


# ---------------- OR-Tools Solver ----------------
def solve_routing_job(job_id: str, payload: dict):
    try:
        prepared = prepare_data(payload)
        n = len(prepared["distance_matrix"])
        if n == 0:
            raise ValueError("No locations provided")

        manager = pywrapcp.RoutingIndexManager(
            n,
            len(prepared["capacities"]),
            prepared["depot_indices"]
        )
        routing = pywrapcp.RoutingModel(manager)

        def distance_cb(from_index, to_index):
            return prepared["distance_matrix"][manager.IndexToNode(from_index)][manager.IndexToNode(to_index)]

        transit_idx = routing.RegisterTransitCallback(distance_cb)
        routing.SetArcCostEvaluatorOfAllVehicles(transit_idx)

        def demand_cb(from_index):
            return prepared["demands"][manager.IndexToNode(from_index)]

        demand_idx = routing.RegisterUnaryTransitCallback(demand_cb)
        routing.AddDimensionWithVehicleCapacity(
            demand_idx,
            0,
            prepared["capacities"],
            True,
            "Capacity"
        )

        search_params = pywrapcp.DefaultRoutingSearchParameters()
        search_params.first_solution_strategy = routing_enums_pb2.FirstSolutionStrategy.PATH_CHEAPEST_ARC
        search_params.local_search_metaheuristic = routing_enums_pb2.LocalSearchMetaheuristic.GUIDED_LOCAL_SEARCH
        search_params.time_limit.FromSeconds(10)

        solution = routing.SolveWithParameters(search_params)
        if not solution:
            update_job(job_id,
                       status="error",
                       progress=100,
                       result=None,
                       error="No feasible solution found")
            return

        routes = []
        for v in range(len(prepared["capacities"])):
            idx = routing.Start(v)
            route, load = [], 0
            while not routing.IsEnd(idx):
                node = manager.IndexToNode(idx)
                load += prepared["demands"][node]
                route.append(node)
                idx = solution.Value(routing.NextVar(idx))
            route.append(manager.IndexToNode(idx))
            routes.append({"vehicle": v, "route": route, "load": load})

        update_job(job_id,
                   status="done",
                   progress=100,
                   result={"routes": routes},
                   error=None)

    except Exception as e:
        tb = traceback.format_exc()
        update_job(job_id, status="error", progress=0, result=None, error=str(e) + "\n" + tb)


# ---------------- Flask Endpoints ----------------
@app.route("/ortools/optimize", methods=["POST"])
def optimize():
    payload = request.get_json(force=True)
    job_id = str(uuid.uuid4())
    JOBS[job_id] = {"status": "running", "progress": 0, "result": None, "error": None}
    save_job(job_id, JOBS[job_id])
    EXECUTOR.submit(solve_routing_job, job_id, payload)
    return jsonify({"job_id": job_id, "status": "running"})


@app.route("/ortools/status/<job_id>", methods=["GET"])
def status(job_id):
    job = JOBS.get(job_id) or load_job(job_id)
    if not job:
        return jsonify({"status": "error", "error": "Job not found"}), 404

    out = {
        "status": job.get("status"),
        "progress": job.get("progress", 0),
        "result": job.get("result"),
        "error": job.get("error")
    }

    if request.args.get("debug") == "1":
        out["debug_payload"] = job.get("payload")  # attach payload for debugging

    return jsonify(out)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
