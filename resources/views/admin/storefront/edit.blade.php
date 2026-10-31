@extends('admin.layouts.app')

@section('title', 'Online Store')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/admin-storefront.css') }}?v={{ filemtime(public_path('assets/css/admin-storefront.css')) }}">
@endpush

@section('content')
    <x-page-header
        title="Online Store"
        subtitle="Customize {{ $shop->name }}'s public shop, hero slides and contact details."
        :crumbs="['Content' => null, 'Online Store' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-ghost btn-sm" href="{{ route('shop.home', $shop) }}" target="_blank" rel="noopener">
                <x-icon name="external-link" :size="15" /> Preview store
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="dash-alert is-success" role="status" style="margin-bottom:16px">
            <span class="dash-alert-text">{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="dash-alert is-danger" role="alert" style="margin-bottom:16px">
            <span class="dash-alert-text">
                Please check the highlighted fields:
                <ul style="margin:6px 0 0;padding-left:20px">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </span>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.storefront.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <section class="card storefront-admin-card">
            <div class="card-header">
                <div>
                    <div class="card-title">Store identity &amp; contact</div>
                    <div class="text-xs text-muted">These details belong to the selected shop only.</div>
                </div>
                @allows('content.sliders.edit')
                    <button type="submit" class="btn btn-primary btn-sm">Save store details</button>
                @endallows
            </div>

            <div class="card-body">
                <div class="storefront-admin-grid">
                    <div class="field">
                        <label for="sf-tagline">Short heading above the slider title</label>
                        <input id="sf-tagline" name="storefront_tagline" class="form-control" maxlength="120"
                               value="{{ old('storefront_tagline', $shop->storefront_tagline) }}"
                               placeholder="A good meal starts here">
                    </div>

                    <div class="field">
                        <label for="sf-phone">Public phone number</label>
                        <input id="sf-phone" name="phone" type="tel" class="form-control" maxlength="30"
                               value="{{ old('phone', $shop->phone) }}" autocomplete="tel">
                    </div>

                    <div class="field">
                        <label for="sf-email">Public email</label>
                        <input id="sf-email" name="email" type="email" class="form-control" maxlength="150"
                               value="{{ old('email', $shop->email) }}" autocomplete="email">
                    </div>

                    <div class="field">
                        <label for="sf-city">City</label>
                        <input id="sf-city" name="city" class="form-control" maxlength="90"
                               value="{{ old('city', $shop->city) }}" autocomplete="address-level2">
                    </div>

                    <div class="field storefront-field-full">
                        <label for="sf-address1">Address line 1</label>
                        <input id="sf-address1" name="address_line1" class="form-control" maxlength="190"
                               value="{{ old('address_line1', $shop->address_line1) }}" autocomplete="address-line1">
                    </div>

                    <div class="field storefront-field-full">
                        <label for="sf-address2">Address line 2</label>
                        <input id="sf-address2" name="address_line2" class="form-control" maxlength="190"
                               value="{{ old('address_line2', $shop->address_line2) }}" autocomplete="address-line2">
                    </div>

                    <div class="field">
                        <label for="sf-state">State</label>
                        <input id="sf-state" name="state" class="form-control" maxlength="90"
                               value="{{ old('state', $shop->state) }}" autocomplete="address-level1">
                    </div>

                    <div class="field">
                        <label for="sf-pincode">PIN code</label>
                        <input id="sf-pincode" name="pincode" class="form-control" maxlength="12"
                               value="{{ old('pincode', $shop->pincode) }}" autocomplete="postal-code">
                    </div>

                    <div class="field storefront-field-full">
                        <label for="sf-country">Country</label>
                        <input id="sf-country" name="country" class="form-control" maxlength="90"
                               value="{{ old('country', $shop->country) }}" autocomplete="country-name">
                    </div>

                    <div class="field">
                        <label for="sf-header-logo">Header logo</label>
                        <input id="sf-header-logo" name="storefront_logo" type="file" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="form-hint">JPG, PNG or WebP, up to 2 MB. Replaces the shop name in the storefront header.</div>
                        @if ($shop->storefrontLogoUrl())
                            <img class="storefront-admin-logo" src="{{ $shop->storefrontLogoUrl() }}" alt="{{ $shop->name }} header logo">
                        @endif
                    </div>

                    <div class="field">
                        <label for="sf-footer-logo">Footer logo</label>
                        <input id="sf-footer-logo" name="storefront_footer_logo" type="file" class="form-control"
                               accept="image/jpeg,image/png,image/webp">
                        <div class="form-hint">JPG, PNG or WebP, up to 2 MB. Leave empty to keep the current logo.</div>
                        @if ($shop->storefrontFooterLogoUrl())
                            <img class="storefront-admin-logo" src="{{ $shop->storefrontFooterLogoUrl() }}" alt="{{ $shop->name }} footer logo">
                        @endif
                    </div>
                </div>
            </div>

            @allows('content.sliders.edit')
                <div class="card-footer storefront-admin-actions">
                    <button type="submit" class="btn btn-primary">Save store details</button>
                </div>
            @endallows
        </section>
    </form>

    <section class="storefront-slides-section">
        <div class="storefront-slides-heading">
            <div>
                <h2>Homepage slider</h2>
                <p class="text-muted">Add up to {{ $maxSlides }} slides. Each slide can have its own photo, heading, description and button.</p>
            </div>
            <span class="badge">{{ $slides->count() }} / {{ $maxSlides }} slides</span>
        </div>

        @foreach ($slides as $slide)
            <article class="card storefront-slide-card">
                <div class="card-header storefront-slide-card-heading">
                    <div>
                        <div class="card-title">{{ $slide->title }}</div>
                        <div class="text-xs text-muted">
                            {{ $slide->is_active ? 'Published' : 'Draft' }} · Position {{ $slide->sort_order + 1 }}
                        </div>
                    </div>
                    @allows('content.sliders.delete')
                        <form method="POST" action="{{ route('admin.storefront.slides.destroy', $slide->id) }}"
                              onsubmit="return confirm('Remove this slide from the shop?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
                        </form>
                    @endallows
                </div>

                <form method="POST" action="{{ route('admin.storefront.slides.update', $slide->id) }}"
                      enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        <div class="storefront-admin-grid">
                            <div class="field">
                                <label for="slide-title-{{ $slide->id }}">Slider title</label>
                                <input id="slide-title-{{ $slide->id }}" name="title" class="form-control"
                                       maxlength="180" required value="{{ $slide->title }}">
                            </div>

                            <div class="field">
                                <label for="slide-order-{{ $slide->id }}">Display order</label>
                                <input id="slide-order-{{ $slide->id }}" name="sort_order" type="number"
                                       class="form-control" min="0" max="65535" value="{{ $slide->sort_order }}">
                            </div>

                            <div class="field storefront-field-full">
                                <label for="slide-description-{{ $slide->id }}">Description</label>
                                <textarea id="slide-description-{{ $slide->id }}" name="description" class="form-control"
                                          rows="3" maxlength="2000">{{ $slide->description }}</textarea>
                            </div>

                            <div class="field">
                                <label for="slide-button-{{ $slide->id }}">Button text</label>
                                <input id="slide-button-{{ $slide->id }}" name="button_label" class="form-control"
                                       maxlength="50" value="{{ $slide->button_label }}" placeholder="Explore menu">
                            </div>

                            <div class="field">
                                <label for="slide-url-{{ $slide->id }}">Button link</label>
                                <input id="slide-url-{{ $slide->id }}" name="button_url" class="form-control"
                                       maxlength="500" value="{{ $slide->button_url }}" placeholder="/shop/.../catalog">
                            </div>

                            <div class="field">
                                <label for="slide-desktop-{{ $slide->id }}">Desktop image</label>
                                <input id="slide-desktop-{{ $slide->id }}" name="desktop_image" type="file"
                                       class="form-control" accept="image/jpeg,image/png,image/webp">
                                <div class="form-hint">Wide image, up to 5 MB. Leave empty to keep the current image.</div>
                                @if ($slide->imageUrl())
                                    <img class="storefront-slide-preview" src="{{ $slide->imageUrl() }}"
                                         alt="{{ $slide->title }} desktop preview">
                                @endif
                            </div>

                            <div class="field">
                                <label for="slide-mobile-{{ $slide->id }}">Mobile image (optional)</label>
                                <input id="slide-mobile-{{ $slide->id }}" name="mobile_image" type="file"
                                       class="form-control" accept="image/jpeg,image/png,image/webp">
                                <div class="form-hint">Portrait crop, up to 5 MB. Desktop image is used if omitted.</div>
                                @if ($slide->mobile_image_path)
                                    <img class="storefront-slide-preview is-mobile" src="{{ $slide->imageUrl('mobile') }}"
                                         alt="{{ $slide->title }} mobile preview">
                                @endif
                            </div>

                            <div class="field storefront-field-full">
                                <label class="check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" @checked($slide->is_active)>
                                    Show this slide on the public shop
                                </label>
                            </div>
                        </div>
                    </div>
                    @allows('content.sliders.edit')
                        <div class="card-footer storefront-admin-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Save slide</button>
                        </div>
                    @endallows
                </form>
            </article>
        @endforeach

        @if ($slides->count() < $maxSlides)
            @allows('content.sliders.create')
                <form method="POST" action="{{ route('admin.storefront.slides.store') }}" enctype="multipart/form-data"
                      class="card storefront-slide-card">
                    @csrf
                    <div class="card-header">
                        <div>
                            <div class="card-title">Add a slider</div>
                            <div class="text-xs text-muted">Upload a wide photo and write the text customers will see over it.</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="storefront-admin-grid">
                            <div class="field">
                                <label for="new-slide-title">Slider title</label>
                                <input id="new-slide-title" name="title" class="form-control" maxlength="180" required
                                       value="{{ old('title') }}" placeholder="Fresh food, made to order">
                            </div>
                            <div class="field">
                                <label for="new-slide-order">Display order</label>
                                <input id="new-slide-order" name="sort_order" type="number" class="form-control"
                                       min="0" max="65535" value="{{ old('sort_order', $slides->count()) }}">
                            </div>
                            <div class="field storefront-field-full">
                                <label for="new-slide-description">Description</label>
                                <textarea id="new-slide-description" name="description" class="form-control"
                                          rows="3" maxlength="2000" placeholder="Tell guests what makes your shop special.">{{ old('description') }}</textarea>
                            </div>
                            <div class="field">
                                <label for="new-slide-button">Button text</label>
                                <input id="new-slide-button" name="button_label" class="form-control" maxlength="50"
                                       value="{{ old('button_label') }}" placeholder="Explore menu">
                            </div>
                            <div class="field">
                                <label for="new-slide-url">Button link</label>
                                <input id="new-slide-url" name="button_url" class="form-control" maxlength="500"
                                       value="{{ old('button_url') }}" placeholder="/shop/.../catalog">
                            </div>
                            <div class="field">
                                <label for="new-slide-desktop">Desktop image</label>
                                <input id="new-slide-desktop" name="desktop_image" type="file" class="form-control"
                                       accept="image/jpeg,image/png,image/webp" required>
                                <div class="form-hint">JPG, PNG or WebP · up to 5 MB · wide landscape image.</div>
                            </div>
                            <div class="field">
                                <label for="new-slide-mobile">Mobile image (optional)</label>
                                <input id="new-slide-mobile" name="mobile_image" type="file" class="form-control"
                                       accept="image/jpeg,image/png,image/webp">
                                <div class="form-hint">Use a portrait crop for a better phone layout.</div>
                            </div>
                            <div class="field storefront-field-full">
                                <label class="check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" checked>
                                    Publish this slide immediately
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer storefront-admin-actions">
                        <button type="submit" class="btn btn-primary">Add slide</button>
                    </div>
                </form>
            @endallows
        @endif
    </section>
@endsection
