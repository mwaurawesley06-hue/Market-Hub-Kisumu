<section class="page-heading">
    <div><div class="eyebrow">Review and publish</div><h1>Approval queue</h1><p>Review pending price observations before they reach farmers, traders, buyers, SMS, and USSD.</p></div>
    <div class="heading-actions"><button class="button button-secondary" type="button" id="refresh-approval"><i class="fa-solid fa-rotate"></i><span>Refresh queue</span></button></div>
</section>
<section class="panel">
    <div class="filters">
        <div class="filter-control"><label for="approval-status">Status</label><select id="approval-status"><option value="pending" selected>Pending</option><option value="">All statuses</option><option value="published">Published</option><option value="rejected">Rejected</option></select></div>
        <div class="filter-control"><label for="approval-commodity">Commodity</label><select id="approval-commodity"><option value="">All commodities</option></select></div>
        <div class="filter-control"><label for="approval-market">Market</label><select id="approval-market"><option value="">All markets</option></select></div>
        <div class="filter-control"><label for="approval-from">From</label><input id="approval-from" type="date"></div>
        <div class="filter-control"><label for="approval-to">To</label><input id="approval-to" type="date"></div>
        <div class="filter-control"><label for="approval-search">Search</label><input id="approval-search" type="search" placeholder="Source or name"></div>
        <div class="filter-actions"><button class="button button-secondary" type="button" id="apply-approval-filters"><i class="fa-solid fa-filter"></i><span>Apply filters</span></button></div>
    </div>
    <div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Price observations in the approval queue</caption><thead><tr><th scope="col">Commodity</th><th scope="col">Market</th><th scope="col">Observed</th><th scope="col">Price</th><th scope="col">Source</th><th scope="col">Captured by</th><th scope="col">Actions</th></tr></thead><tbody id="approval-table"><tr><td colspan="7" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading pending prices</span></td></tr></tbody></table></div>
    <div class="table-pagination"><span id="approval-count">Loading queue…</span><div class="table-pagination-actions"><button class="button button-small button-secondary" type="button" id="approval-previous" disabled><i class="fa-solid fa-chevron-left"></i><span>Previous</span></button><button class="button button-small button-secondary" type="button" id="approval-next" disabled><span>Next</span><i class="fa-solid fa-chevron-right"></i></button></div></div>
</section>
<section class="panel">
    <div class="panel-header">
        <div>
            <h2>SMS delivery failures</h2>
            <p>Verified subscribers whose alert could not be delivered during the last publish.</p>
        </div>
        <button class="button button-small button-secondary" type="button" id="sms-failures-refresh"><i class="fa-solid fa-rotate"></i><span>Refresh</span></button>
    </div>
    <div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Recent SMS delivery failures</caption><thead><tr><th scope="col">Phone</th><th scope="col">Error</th><th scope="col">Message</th><th scope="col">When</th></tr></thead><tbody id="sms-failures"><tr><td colspan="4" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Checking delivery failures</span></td></tr></tbody></table></div>
</section>
<div class="modal" id="reject-modal" role="dialog" aria-modal="true" aria-labelledby="reject-title"><div class="modal-dialog"><div class="modal-header"><h2 id="reject-title">Reject price record</h2><button class="icon-button" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div><div class="modal-body"><p class="modal-message">A rejection reason is required so the submitter knows what to correct.</p><div class="form-field"><label for="reject-reason">Reason <span>*</span></label><textarea id="reject-reason" maxlength="1000" placeholder="Explain why this record cannot be published."></textarea><span class="field-error" data-error-for="reason"></span></div></div><div class="modal-footer"><button class="button button-secondary" type="button" data-modal-close>Cancel</button><button class="button button-danger" type="button" id="confirm-reject"><i class="fa-solid fa-xmark"></i><span>Reject record</span></button></div></div></div>
