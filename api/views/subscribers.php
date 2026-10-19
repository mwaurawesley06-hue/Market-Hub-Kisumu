<section class="page-heading">
    <div>
        <div class="eyebrow">Channels</div>
        <h1>SMS subscribers</h1>
        <p>Every phone subscribed to price alerts, with its verification state and last alert time.</p>
    </div>
    <div class="heading-actions"><button class="button button-secondary" type="button" id="subscribers-export"><i class="fa-solid fa-file-csv"></i><span>Export CSV</span></button><button class="button button-secondary" type="button" id="subscribers-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button></div>
</section>
<section class="content-grid">
    <div class="panel form-panel">
        <div class="panel-header">
            <div>
                <h2>Subscribers</h2>
                <p>Sorted by subscription date, newest first.</p>
            </div>
            <span class="badge badge-soft-info"><i class="fa-solid fa-bell"></i><span id="subscribers-count">—</span></span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <caption class="visually-hidden">SMS subscribers with verification status</caption>
                <thead>
                    <tr>
                        <th scope="col">Phone number</th>
                        <th scope="col">Commodity</th>
                        <th scope="col">Frequency</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last alert</th>
                        <th scope="col">Subscribed</th>
                    </tr>
                </thead>
                <tbody id="subscribers-rows">
                    <tr><td colspan="6" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading subscribers</span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <aside class="panel">
        <div class="panel-header"><div><h2>Legend</h2><p>What each status means.</p></div></div>
        <div class="panel-body steps-list">
            <div class="step"><span class="badge badge-soft-active"><i class="fa-solid fa-circle-check"></i>verified</span><div><strong>Alerts active</strong><p>The phone confirmed its code and receives published prices.</p></div></div>
            <div class="step"><span class="badge badge-soft-warning"><i class="fa-solid fa-hourglass-half"></i>pending</span><div><strong>Awaiting verification</strong><p>The 6-digit code was sent but not confirmed yet; no alerts are sent.</p></div></div>
        </div>
    </aside>
</section>
