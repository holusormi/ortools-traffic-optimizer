 
<div id="dispatcher-app">
    <button @click="startOptimization">Dispatch Now</button>

    <div v-if="status==='processing'">
        <p>Optimization running... <span class="spinner"></span></p>
    </div>

    <div v-if="status==='done'">
        <h3>Assignments</h3>
        <ul>
            <li v-for="(route, idx) in result.routes">
                Vehicle @{{ idx+1 }} → @{{ route.length }} stops
            </li>
        </ul>

        <h3>Unassigned</h3>
        <ul>
            <li v-for="order in result.unassigned">@{{ order }}</li>
        </ul>
    </div>
</div>
 

@push('scripts')
<script>
new Vue({
    el: '#dispatcher-app',
    data: { status: null, jobId: null, result: {} },
    methods: {
        startOptimization() {
            axios.post("{{ route('admin.dispatchers.dispatchNow') }}")
                .then(res => {
                    this.jobId = res.data.job_id;
                    this.poll();
                });
        },
        poll() {
            if (!this.jobId) return;
            axios.get("/admin/dispatchers/status/"+this.jobId)
                .then(res => {
                    if (res.data.status==='processing') {
                        this.status = 'processing';
                        setTimeout(this.poll, 3000);
                    } else {
                        this.status = 'done';
                        this.result = res.data.result;
                    }
                });
        }
    }
})
</script>
@endpush
