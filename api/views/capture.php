<section class="page-heading">
    <div><div class="eyebrow">Price workflow</div><h1>Capture a market price</h1><p>Record a verified observation for approval. Only published prices appear on the public dashboard.</p></div>
    <div class="heading-actions"><a class="button button-secondary" href="records"><i class="fa-solid fa-list"></i><span>View records</span></a></div>
</section>
<section class="panel form-panel">
    <div class="panel-header"><div><h2>New price record</h2><p>Required fields are marked with an asterisk.</p></div><span class="badge badge-soft-pending"><i class="fa-solid fa-clock"></i>Starts pending</span></div>
    <div class="panel-body">
        <form id="capture-form" novalidate>
            <div class="form-grid">
                <div class="form-field"><label for="commodity">Commodity <span>*</span></label><select id="commodity" name="commodity" required><option value="">Loading commodities…</option></select><span class="field-error" data-error-for="commodity"></span></div>
                <div class="form-field"><label for="market">Market <span>*</span></label><select id="market" name="market" required><option value="">Loading markets…</option></select><span class="field-error" data-error-for="market"></span></div>
                <div class="form-field"><label for="date_observed">Date observed <span>*</span></label><input id="date_observed" name="date_observed" type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required><span class="field-error" data-error-for="date_observed"></span></div>
                <div class="form-field"><label for="price">Price <span>*</span><span class="range-hint" id="price-range"><i class="fa-solid fa-circle-info"></i>Select a commodity first</span></label><input id="price" name="price" type="number" min="0.01" step="0.01" placeholder="0.00" required><span class="field-error" data-error-for="price"></span></div>
                <div class="form-field form-field-full"><label for="source">Source <span>*</span></label><input id="source" name="source" type="text" maxlength="120" placeholder="e.g. Kisumu Central Market desk" required><span class="form-hint">Use a source that identifies where the observation came from.</span><span class="field-error" data-error-for="source"></span></div>
            </div>
            <div class="form-actions"><a class="button button-secondary" href="dashboard">Cancel</a><button class="button button-primary" type="submit" id="capture-submit"><i class="fa-solid fa-paper-plane"></i><span>Submit for approval</span></button></div>
        </form>
    </div>
</section>
<div class="modal" id="duplicate-modal" role="dialog" aria-modal="true" aria-labelledby="duplicate-title">
    <div class="modal-dialog modal-dialog-wide">
        <div class="modal-header modal-header-warning"><h2 id="duplicate-title"><i class="fa-solid fa-triangle-exclamation"></i> Duplicate entry detected</h2><button class="icon-button" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal-body">
            <p class="modal-message">A price for this commodity at this market on this date already exists. Choose how to continue.</p>
            <div class="duplicate-context" id="duplicate-context"></div>
            <div class="duplicate-columns">
                <div class="duplicate-card">
                    <h3><i class="fa-solid fa-database"></i> Existing record</h3>
                    <dl class="duplicate-detail" id="duplicate-existing"></dl>
                </div>
                <div class="duplicate-card">
                    <h3><i class="fa-solid fa-pen-to-square"></i> New entry</h3>
                    <dl class="duplicate-detail" id="duplicate-new"></dl>
                </div>
            </div>
            <div class="duplicate-detail-panel" id="duplicate-detail-panel" hidden>
                <h3><i class="fa-solid fa-circle-info"></i> Existing record detail</h3>
                <dl class="duplicate-detail" id="duplicate-detail"></dl>
            </div>
            <div class="form-field" id="override-field">
                <label for="override_justification">Override justification <span>*</span></label>
                <textarea id="override_justification" name="override_justification" maxlength="1000" rows="3" placeholder="Explain why a second price is justified — e.g. a new consignment arrived and the market price changed."></textarea>
                <span class="form-hint">Required for an override. State the market circumstance — the reason is stored with the record and in the audit trail.</span>
                <span class="field-error" data-error-for="override_justification"></span>
            </div>
        </div>
        <div class="modal-footer duplicate-footer">
            <button class="button button-secondary" type="button" id="duplicate-abandon"><i class="fa-solid fa-ban"></i><span>Abandon Entry</span></button>
            <button class="button button-secondary" type="button" id="duplicate-view"><i class="fa-solid fa-eye"></i><span>View Existing Record</span></button>
            <button class="button button-danger" type="button" id="confirm-override" disabled><i class="fa-solid fa-arrows-rotate"></i><span>Override and Submit</span></button>
        </div>
    </div>
</div>
