<section class="page-heading">
    <div>
        <div class="eyebrow">Operations overview</div>
        <h1>Good morning, team</h1>
        <p>Keep every market price accurate, current, and visible from one authoritative workspace.</p>
    </div>
    <div class="heading-actions"><a class="button button-primary" href="capture"><i class="fa-solid fa-plus"></i><span>Capture a price</span></a></div>
</section>
<section class="widget-grid" aria-label="Market information summary">
    <a class="dashboard-widget" href="approval?status=pending"><div><span class="widget-value" id="stat-pending">—</span><span class="widget-label">Pending approval</span><span class="widget-link">Review queue <i class="fa-solid fa-arrow-right"></i></span></div><span class="widget-icon"><i class="fa-solid fa-hourglass-half"></i></span></a>
    <a class="dashboard-widget" href="records?status=published"><div><span class="widget-value" id="stat-published">—</span><span class="widget-label">Published prices</span><span class="widget-link">View records <i class="fa-solid fa-arrow-right"></i></span></div><span class="widget-icon"><i class="fa-solid fa-circle-check"></i></span></a>
    <a class="dashboard-widget" href="public-dashboard"><div><span class="widget-value" id="stat-commodities">—</span><span class="widget-label">Active commodities</span><span class="widget-link">Open public site <i class="fa-solid fa-arrow-right"></i></span></div><span class="widget-icon"><i class="fa-solid fa-wheat-awn"></i></span></a>
    <a class="dashboard-widget" href="subscriptions"><div><span class="widget-value" id="stat-subscribers">—</span><span class="widget-label">Verified SMS subscribers</span><span class="widget-link">Manage alerts <i class="fa-solid fa-arrow-right"></i></span></div><span class="widget-icon"><i class="fa-solid fa-bell"></i></span></a>
</section>
<section class="content-grid">
    <div class="panel">
        <div class="panel-header"><div><h2>Recent price activity</h2><p>Latest submissions and decisions from your team.</p></div><a class="button button-ghost" href="records">See all <i class="fa-solid fa-arrow-right"></i></a></div>
        <div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Most recent price submissions and their status</caption><thead><tr><th scope="col">Commodity</th><th scope="col">Market</th><th scope="col">Observed</th><th scope="col">Price</th><th scope="col">Status</th></tr></thead><tbody id="recent-records"><tr><td colspan="5" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading activity</span></td></tr></tbody></table></div>
    </div>
    <div class="panel">
        <div class="panel-header"><div><h2>Quick actions</h2><p>Common workspace tasks.</p></div></div>
        <div class="panel-body quick-actions">
            <a class="quick-action" href="capture"><span class="quick-action-icon"><i class="fa-solid fa-plus"></i></span><span><strong>Capture a price</strong><small>Submit a verified market observation</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="quick-action" href="approval"><span class="quick-action-icon"><i class="fa-solid fa-clipboard-check"></i></span><span><strong>Review approvals</strong><small>Publish or reject pending records</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="quick-action" href="reports"><span class="quick-action-icon"><i class="fa-solid fa-chart-column"></i></span><span><strong>Generate a report</strong><small>Compare published price trends</small></span><i class="fa-solid fa-chevron-right"></i></a>
            <a class="quick-action" href="audit-logs"><span class="quick-action-icon"><i class="fa-solid fa-shield-halved"></i></span><span><strong>Inspect audit trail</strong><small>Trace every state-changing action</small></span><i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
</section>
