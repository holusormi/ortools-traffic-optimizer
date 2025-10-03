<div class="container-fluid">
    <h3>Dispatcher 
        <button id="dispatchNowBtn" class="btn btn-primary">Dispatch Now</button>
        <span id="statusText" class="ml-3"></span>
    </h3>
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
            <div id="map" style="height:600px; border: 1px solid #ccc;"></div>
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
    const statusText = document.getElementById('statusText');
    let jobId = null;
    let map, markers = [], polylines = [];

    function initMap() {
        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 10,
            center: { lat: 43.2141, lng: 27.9147 } // Varna, Bulgaria
        });
        console.log('Map initialized');
    }

    function updateStatus(text, isError = false) {
        statusText.textContent = text;
        statusText.style.color = isError ? 'red' : 'green';
    }

    dispatchBtn.addEventListener('click', () => {
        dispatchBtn.disabled = true;
        updateStatus('Starting optimization...');
        
        fetch('{{ route("admin.dispatchers.dispatchNow") }}', { 
            method: 'POST', 
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        })
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(j => {
            if (!j.job_id) {
                updateStatus('Error: ' + (j.error || 'Job dispatch failed'), true);
                console.error('Dispatch error:', j);
                return;
            }
            jobId = j.job_id;
            updateStatus(`Job ${jobId.substr(0,8)}... started`);
            console.log('Job started:', jobId);
            pollJob();
        })
        .catch(err => {
            updateStatus('Error: ' + err.message, true);
            console.error('Dispatch error:', err);
        })
        .finally(() => {
            dispatchBtn.disabled = false;
        });
    });

    function pollJob() {
        if (!jobId) return;
        
        fetch(`/admin/dispatchers/status/${jobId}`)
            .then(r => r.json())
            .then(data => {
                console.log('Job status:', data);
                
                const status = data.status;
                const progress = data.progress || 0;
                
                if (status === 'running' || status === 'processing') {
                    updateStatus(`Optimizing... ${progress}%`);
                    setTimeout(pollJob, 2000);
                    return;
                }
                
                if (status === 'done') {
                    updateStatus('Optimization complete!');
                    renderAssignments(data);
                } else if (status === 'error') {
                    updateStatus('Error: ' + (data.error || 'Unknown error'), true);
                    console.error('Job error:', data.error);
                }
            })
            .catch(err => {
                updateStatus('Polling error: ' + err.message, true);
                console.error('Poll error:', err);
            });
    }

    function renderAssignments(data) {
        const vehicleListEl = document.getElementById('vehicleList');
        const unassignedEl = document.getElementById('unassignedList');
        vehicleListEl.innerHTML = '';
        unassignedEl.innerHTML = '';

        const prepared = data.prepared || {};
        const result = data.result || {};
        const locations = prepared.locations || [];
        const whCount = prepared.meta?.warehouses_count || 0;

        console.log('Rendering assignments:', {
            locations: locations.length,
            warehouses: whCount,
            routes: result.routes?.length
        });

        // Show unassigned orders
        const unassigned = result.unassigned_orders || [];
        if (unassigned.length > 0) {
            unassigned.forEach(orderIdx => {
                const locIdx = whCount + orderIdx;
                if (locIdx < locations.length) {
                    const loc = locations[locIdx];
                    const li = document.createElement('li');
                    li.className = 'list-group-item list-group-item-warning';
                    li.textContent = `Order #${orderIdx + 1} — ${loc[0].toFixed(5)}, ${loc[1].toFixed(5)}`;
                    unassignedEl.appendChild(li);
                }
            });
        } else {
            const li = document.createElement('li');
            li.className = 'list-group-item text-success';
            li.textContent = 'All orders assigned!';
            unassignedEl.appendChild(li);
        }

        // Show vehicles & assignments
        const routes = result.routes || [];
        routes.forEach((route, vIdx) => {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            const body = document.createElement('div');
            body.className = 'card-body';
            
            const details = result.route_details?.[vIdx] || {};
            const title = document.createElement('h6');
            title.textContent = `Vehicle ${vIdx + 1}`;
            body.appendChild(title);
            
            if (details.total_distance || details.total_load) {
                const info = document.createElement('small');
                info.className = 'text-muted';
                info.textContent = `Distance: ${(details.total_distance/1000).toFixed(1)}km | Load: ${details.total_load}`;
                body.appendChild(info);
                body.appendChild(document.createElement('br'));
            }

            const ul = document.createElement('ul');
            ul.className = 'list-group mt-2';
            
            route.forEach((stop, stopIdx) => {
                const locIdx = stop.location_index;
                
                // Skip depot entries in display (show only orders)
                if (locIdx >= whCount) {
                    const stopLi = document.createElement('li');
                    stopLi.className = 'list-group-item list-group-item-sm';
                    const orderNum = locIdx - whCount + 1;
                    stopLi.textContent = `${stopIdx}. Order #${orderNum} (Load: ${stop.load})`;
                    ul.appendChild(stopLi);
                }
            });

            const navBtn = document.createElement('button');
            navBtn.className = 'btn btn-sm btn-outline-primary mt-2';
            navBtn.textContent = 'Open Navigation';
            navBtn.addEventListener('click', () => {
                openNavigationForVehicle(route, locations);
            });

            body.appendChild(ul);
            body.appendChild(navBtn);
            card.appendChild(body);
            vehicleListEl.appendChild(card);
        });

        drawMapFromAssignments(routes, locations, whCount);
    }

    function openNavigationForVehicle(route, locations) {
        if (!route.length) return;

        const coords = route.map(stop => locations[stop.location_index]);
        if (coords.length < 2) return;

        const origin = coords[0];
        const destination = coords[coords.length - 1];
        const waypoints = coords.slice(1, -1);

        const waypointsParam = waypoints.map(w => `${w[0]},${w[1]}`).join('|');
        const base = `https://www.google.com/maps/dir/?api=1&origin=${origin[0]},${origin[1]}&destination=${destination[0]},${destination[1]}&travelmode=driving`;
        const url = waypointsParam ? `${base}&waypoints=${encodeURIComponent(waypointsParam)}` : base;
        
        window.open(url, '_blank');
    }

    function drawMapFromAssignments(routes, locations, whCount) {
        // Clear existing markers and polylines
        markers.forEach(m => m.setMap(null));
        polylines.forEach(p => p.setMap(null));
        markers = [];
        polylines = [];

        if (!locations.length) {
            console.warn('No locations to draw');
            return;
        }

        const bounds = new google.maps.LatLngBounds();

        // Add warehouse markers (depots)
        for (let i = 0; i < whCount; i++) {
            const loc = locations[i];
            const marker = new google.maps.Marker({
                position: { lat: loc[0], lng: loc[1] },
                map: map,
                label: {
                    text: `W${i + 1}`,
                    color: 'white',
                    fontSize: '12px',
                    fontWeight: 'bold'
                },
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 12,
                    fillColor: '#4CAF50',
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 2
                }
            });
            markers.push(marker);
            bounds.extend(marker.position);
        }

        // Add order markers
        for (let i = whCount; i < locations.length; i++) {
            const loc = locations[i];
            const orderNum = i - whCount + 1;
            const marker = new google.maps.Marker({
                position: { lat: loc[0], lng: loc[1] },
                map: map,
                label: {
                    text: `${orderNum}`,
                    color: 'white',
                    fontSize: '10px'
                },
                icon: {
                    path: google.maps.SymbolPath.CIRCLE,
                    scale: 8,
                    fillColor: '#2196F3',
                    fillOpacity: 1,
                    strokeColor: '#fff',
                    strokeWeight: 1
                }
            });
            markers.push(marker);
            bounds.extend(marker.position);
        }

        // Draw route polylines
        const colors = ['#FF0000', '#0000FF', '#00AA00', '#FF00FF', '#FFA500', '#800080'];
        routes.forEach((route, vIdx) => {
            const path = route.map(stop => {
                const loc = locations[stop.location_index];
                return { lat: loc[0], lng: loc[1] };
            });

            if (path.length > 1) {
                const polyline = new google.maps.Polyline({
                    path: path,
                    map: map,
                    strokeColor: colors[vIdx % colors.length],
                    strokeWeight: 3,
                    strokeOpacity: 0.7
                });
                polylines.push(polyline);
            }
        });

        // Fit map to show all markers
        if (markers.length > 0) {
            map.fitBounds(bounds);
        }
    }

    // Initialize map when Google Maps loads
    if (typeof google === 'object' && google.maps) {
        initMap();
    } else {
        window.initMap = initMap;
    }
});
</script>
@endsection
