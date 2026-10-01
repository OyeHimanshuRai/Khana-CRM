{{-- The fields of a supplier, for the add and edit forms. $supplier or null. --}}
<div class="form-row">
    <div class="field">
        <label class="form-label" for="name">Name</label>
        <input class="form-control" id="name" name="name" value="{{ old('name', $supplier?->name) }}" required>
        @error('name')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
        <label class="form-label" for="contact_name">Contact person <span class="text-muted">(optional)</span></label>
        <input class="form-control" id="contact_name" name="contact_name" value="{{ old('contact_name', $supplier?->contact_name) }}">
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label class="form-label" for="phone">Phone <span class="text-muted">(optional)</span></label>
        <input class="form-control" id="phone" name="phone" value="{{ old('phone', $supplier?->phone) }}" maxlength="20">
    </div>
    <div class="field">
        <label class="form-label" for="email">Email <span class="text-muted">(optional)</span></label>
        <input class="form-control" id="email" name="email" type="email" value="{{ old('email', $supplier?->email) }}">
        @error('email')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
        <label class="form-label" for="terms_days">Pay within (days)</label>
        <input class="form-control" id="terms_days" name="terms_days" inputmode="numeric" value="{{ old('terms_days', $supplier?->terms_days) }}" placeholder="0 = on delivery">
        @error('terms_days')<div class="field-error">{{ $message }}</div>@enderror
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label class="form-label" for="gstin">GSTIN <span class="text-muted">(optional)</span></label>
        <input class="form-control" id="gstin" name="gstin" value="{{ old('gstin', $supplier?->gstin) }}" maxlength="15">
        @error('gstin')<div class="field-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
        <label class="form-label" for="address">Address <span class="text-muted">(optional)</span></label>
        <input class="form-control" id="address" name="address" value="{{ old('address', $supplier?->address) }}">
    </div>
</div>
