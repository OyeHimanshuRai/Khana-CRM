{{--
    What ERP adds to a menu item's page. Included by _show.

    Linked to ERP: a panel that is filled after the page has drawn -
    assets/js/erp-panels.js asks ErpPanelController::stock for it. Nothing
    here reads ERP, so the item opens just as fast with ERP switched off.

    CRM alone: stock, recipes and purchasing appear nowhere in CRM, so this
    is the one place that says they could - in a sentence or two, where the
    figures would be, and without asking ERP anything.

    Neither, and so nothing at all, in the All-shops view and for an outlet
    that owns ERP on an install that has no address for it.
--}}

@if ($erpLinked)
    <div style="margin-top:16px">
        <div class="form-label">Stock and cost, from ERP</div>

        <div data-erp-panel="{{ route('admin.products.erp-stock', $product) }}">
            <p class="text-sm text-muted" style="margin:0">Asking ERP…</p>
        </div>
    </div>
@elseif ($erpAlone)
    <div style="margin-top:16px;padding:12px 14px;border:1px dashed var(--border-strong)">
        <div class="form-label">Stock and cost</div>
        <p class="text-sm text-muted" style="margin:0">
            CRM does not keep stock. With Khana ERP, this page shows how many portions the stock on hand
            covers and what each one costs to make, worked out from the recipe — and the dish goes off
            sale by itself when the stock runs out.
        </p>
    </div>
@endif
