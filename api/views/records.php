<section class="page-heading">
    <div><div class="eyebrow">Price history</div><h1>Price records</h1><p>Search every observation, including pending and rejected records, with the decision trail intact.</p></div>
    <div class="heading-actions"><a class="button button-primary" href="capture"><i class="fa-solid fa-plus"></i><span>Capture price</span></a></div>
</section>
<section class="panel">
    <div class="filters">
        <div class="filter-control"><label for="records-status">Status</label><select id="records-status"><option value="">All statuses</option><option value="pending">Pending</option><option value="published">Published</option><option value="rejected">Rejected</option></select></div>
        <div class="filter-control"><label for="records-commodity">Commodity</label><select id="records-commodity"><option value="">All commodities</option></select></div>
        <div class="filter-control"><label for="records-market">Market</label><select id="records-market"><option value="">All markets</option></select></div>
        <div class="filter-control"><label for="records-from">From</label><input id="records-from" type="date"></div>
        <div class="filter-control"><label for="records-to">To</label><input id="records-to" type="date"></div>
        <div class="filter-control"><label for="records-search">Search</label><input id="records-search" type="search" placeholder="Source or name"></div>
        <div class="filter-actions"><button class="button button-secondary" type="button" id="apply-record-filters"><i class="fa-solid fa-filter"></i><span>Filter</span></button></div>
    </div>
    <div class="table-wrap"><table class="data-table"><caption class="visually-hidden">Price records with their status and decision trail</caption><thead><tr><th scope="col">Commodity</th><th scope="col">Market</th><th scope="col">Observed</th><th scope="col">Price</th><th scope="col">Status</th><th scope="col">Decision trail</th><th scope="col">Actions</th></tr></thead><tbody id="records-table"><tr><td colspan="7" class="table-loading"><i class="fa-solid fa-circle-notch fa-spin"></i><span>Loading price records</span></td></tr></tbody></table></div>
    <div class="table-pagination"><span id="records-count">Loading records…</span><div class="table-pagination-actions"><button class="button button-small button-secondary" type="button" id="records-previous" disabled><i class="fa-solid fa-chevron-left"></i><span>Previous</span></button><button class="button button-small button-secondary" type="button" id="records-next" disabled><span>Next</span><i class="fa-solid fa-chevron-right"></i></button></div></div>
</section>
<div class="modal" id="record-edit-modal" role="dialog" aria-modal="true" aria-labelledby="record-edit-title">
    <div class="modal-dialog">
        <div class="modal-header"><h2 id="record-edit-title">Correct rejected record</h2><button class="icon-button" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <form id="record-edit-form" novalidate>
            <div class="modal-body">
                <div class="decision-context" id="edit-record-context"></div>
                <div class="form-grid">
                    <div class="form-field"><label for="edit-commodity">Commodity <span>*</span></label><select id="edit-commodity" name="commodity" required><option value="">Select a commodity</option></select><span class="field-error" data-error-for="commodity"></span></div>
                    <div class="form-field"><label for="edit-market">Market <span>*</span></label><select id="edit-market" name="market" required><option value="">Select a market</option></select><span class="field-error" data-error-for="market"></span></div>
                    <div class="form-field"><label for="edit-date">Date observed <span>*</span></label><input id="edit-date" name="date_observed" type="date" max="<?= date('Y-m-d') ?>" required><span class="field-error" data-error-for="date_observed"></span></div>
                    <div class="form-field"><label for="edit-price">Price <span>*</span><span class="range-hint" id="edit-price-range"><i class="fa-solid fa-circle-info"></i>Select a commodity first</span></label><input id="edit-price" name="price" type="number" min="0.01" step="0.01" required><span class="field-error" data-error-for="price"></span></div>
                    <div class="form-field form-field-full"><label for="edit-source">Source <span>*</span></label><input id="edit-source" name="source" type="text" maxlength="120" required><span class="field-error" data-error-for="source"></span></div>
                </div>
            </div>
            <div class="modal-footer"><button class="button button-secondary" type="button" data-modal-close>Cancel</button><button class="button button-primary" type="submit"><i class="fa-solid fa-check"></i><span>Save and resubmit</span></button></div>
        </form>
    </div>
</div>
