 
<div class="container-fluid">
    <h3>Dispatcher  <button id="dispatchNowBtn" class="btn btn-primary">Dispatch Now</button></h3>
    <div class="row">
        <!-- Unassigned Orders -->
        <div class="col-md-3">
            <h5>Unassigned Orders</h5>
            <ul id="unassignedList" class="list-group">
                <!-- Filled dynamically -->
            </ul>
        </div>

        <!-- Map -->
        <div class="col-md-6">
            <div id="map" style="height:600px;"></div>
        </div>

        <!-- Vehicles -->
        <div class="col-md-3">
            <h5>Vehicles / Assignments</h5>
            <div id="vehicleList"></div>
        </div>
    </div>
 
</div>
 

@section('after-scripts')
 
 <script src="https://maps.googleapis.com/maps/api/js?key={{ $GapiKey }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dispatchBtn = document.getElementById('dispatchNowBtn');
    let jobId = null;
    let map, markers = [], polylines = [];

    function initMap() {
        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 12,
            center: { lat: 43.0, lng: 27.0 }
        });
    }
    window.initMap = initMap;

    dispatchBtn.addEventListener('click', () => {
        dispatchBtn.disabled = true;
        fetch('{{ route("admin.dispatchers.dispatchNow") }}', { 
            method: 'POST', 
            headers: {'X-CSRF-TOKEN':'{{ csrf_token() }}' }
        })
        .then(r => r.json())
        .then(j => {
            if (!j.job_id) {
                alert(j.error || 'Job dispatch failed');
                return;
            }
            jobId = j.job_id;
            pollJob();
        })
        .finally(() => dispatchBtn.disabled = false);
    });

    function pollJob() {
        if (!jobId) return;
        fetch(`/admin/dispatchers/status/${jobId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status === 'running' || data.status === 'processing') {
                    setTimeout(pollJob, 2000);
                    return;
                }
                renderAssignments(data);
            })
            .catch(err => console.error(err));
    }

    function renderAssignments(data) {
        const vehicleListEl = document.getElementById('vehicleList');
        const unassignedEl = document.getElementById('unassignedList');
        vehicleListEl.innerHTML = '';
        unassignedEl.innerHTML = '';

        const prepared = data.result?.prepared || data.prepared || {};
        const locations = prepared.locations || [];
        const whCount = prepared.meta?.warehouses_count || 0;
        const ordersCount = prepared.meta?.orders_count || 0;

        // Show unassigned orders
        const unassigned = data.result?.unassigned_orders || prepared.unassigned_orders || [];
        unassigned.forEach(orderIdx => {
            const loc = locations[whCount + orderIdx];
            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.textContent = `Order #${orderIdx+1} — ${loc[0].toFixed(5)}, ${loc[1].toFixed(5)}`;
            li.draggable = true;
            li.dataset.orderIdx = orderIdx;
            unassignedEl.appendChild(li);
        });

        // Show vehicles & assignments
        const assignments = data.result?.routes || [];
        assignments.forEach((route, vIdx) => {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            const body = document.createElement('div');
            body.className = 'card-body';
            const title = document.createElement('h6');
            title.textContent = `Vehicle ${vIdx+1}`;
            body.appendChild(title);

            const ul = document.createElement('ul');
            ul.className = 'list-group';
            route.forEach(stop => {
                if (stop.location_index >= whCount) { // only orders
                    const stopLi = document.createElement('li');
                    stopLi.className = 'list-group-item';
                    stopLi.textContent = `Order #${stop.location_index - whCount + 1} — ETA ${stop.arrival || ''}`;
                    ul.appendChild(stopLi);
                }
            });

            const navBtn = document.createElement('button');
            navBtn.className = 'btn btn-sm btn-outline-primary mt-2';
            navBtn.textContent = 'Open Navigation';
            navBtn.addEventListener('click', () => {
                openNavigationForVehicle(route, prepared, vIdx);
            });

            body.appendChild(ul);
            body.appendChild(navBtn);
            card.appendChild(body);
            vehicleListEl.appendChild(card);
        });

        drawMapFromAssignments(assignments, prepared, whCount);
    }

    function openNavigationForVehicle(route, prepared, vehicleIdx) {
        const locations = prepared.locations || [];
        if (!route.length) return;

        const origin = locations[route[0].location_index];
        const waypoints = route.slice(1).map(r => locations[r.location_index]);
        const destination = waypoints[waypoints.length - 1];

        const waypointsParam = waypoints.slice(0, -1).map(w => `${w[0]},${w[1]}`).join('|');
        const base = `https://www.google.com/maps/dir/?api=1&origin=${origin[0]},${origin[1]}&destination=${destination[0]},${destination[1]}&travelmode=driving`;
        const url = waypointsParam ? `${base}&waypoints=${encodeURIComponent(waypointsParam)}` : base;
        window.open(url, '_blank');
    }

    function drawMapFromAssignments(assignments, prepared, whCount) {
        markers.forEach(m => m.setMap(null));
        polylines.forEach(p => p.setMap(null));
        markers = []; polylines = [];

        const locations = prepared.locations || [];

        // add markers
        locations.forEach((loc, idx) => {
            const marker = new google.maps.Marker({
                position: { lat: loc[0], lng: loc[1] },
                map: map,
                label: `${idx}`
            });
            markers.push(marker);
        });

        // draw polylines
        assignments.forEach((route, vi) => {
            const path = [];
            route.forEach(stop => {
                const ll = locations[stop.location_index];
                path.push({ lat: ll[0], lng: ll[1] });
            });
            if (path.length > 1) {
                const pl = new google.maps.Polyline({
                    path: path,
                    map: map,
                    strokeColor: ['#FF0000','#0000FF','#00AA00','#FF00FF'][vi % 4],
                    strokeWeight: 3
                });
                polylines.push(pl);
            }
        });
    }

    if (typeof google === 'object') initMap();
    else window.addEventListener('load', initMap);
});
 

</script>
@endsection
