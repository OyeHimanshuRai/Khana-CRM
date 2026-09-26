<?php

/*
|--------------------------------------------------------------------------
| Company settings schema
|--------------------------------------------------------------------------
|
| Single source of truth for the Settings > General screen. The form, its
| validation rules, the defaults seeder and the settings() helper are all
| generated from this file - nothing is hard-coded in the controller or the
| view. Adding a field is one entry here and nothing else.
|
| Section keys:
|   label   card heading
|   hint    small line under the heading
|   icon    name from resources/views/components/icon.blade.php
|   fields  [key => definition]
|
| Field keys:
|   label       required
|   type        text | email | tel | url | number | textarea | select
|               | secret | image | checkbox    (default: text)
|   rules       Laravel validation rules; always append to the type's own
|   hint        small line under the input
|   placeholder input placeholder
|   options     [value => label]  for type "select"
|   default     value to show when nothing is stored yet - a "select"
|               falls back to its first option without one, but a
|               "checkbox" defaults to off unless this says '1'
|   width       "full" to span the whole row (default: half)
|   note        rendered under an image preview, e.g. recommended size
|
| Fields of type "secret" are never sent back to the browser: the input
| renders empty and an empty submit keeps whatever is stored.
|
*/

return [

    'company' => [
        'label' => 'Company Information',
        'hint' => 'Identity and contact details used across the site and documents.',
        'icon' => 'building',
        'fields' => [
            'company_name' => [
                'label' => 'Company Name',
                'rules' => ['required', 'string', 'max:150'],
            ],
            'company_title' => [
                'label' => 'Company Title',
                'rules' => ['nullable', 'string', 'max:150'],
            ],
            'currency' => [
                'label' => 'Currency',
                'type' => 'select',
                'options' => [
                    'INR' => 'Indian Rupee - INR - ₹',
                    'USD' => 'US Dollar - USD - $',
                    'EUR' => 'Euro - EUR - €',
                    'GBP' => 'Pound Sterling - GBP - £',
                    'AED' => 'UAE Dirham - AED - د.إ',
                ],
                'rules' => ['required'],
            ],
            'company_email' => [
                'label' => 'Company Email',
                'type' => 'email',
                'rules' => ['nullable', 'email', 'max:150'],
            ],
            'phone' => [
                'label' => 'Phone No',
                'type' => 'tel',
                'rules' => ['nullable', 'string', 'max:60'],
            ],
            'pan_no' => [
                'label' => 'PAN No',
                'placeholder' => 'Enter PAN number',
                'rules' => ['nullable', 'string', 'max:20'],
            ],
            'eic_no' => [
                'label' => 'EIC No',
                'placeholder' => 'Enter EIC number',
                'rules' => ['nullable', 'string', 'max:30'],
            ],
        ],
    ],

    'social' => [
        'label' => 'Social Links',
        'hint' => 'Left blank, the matching icon is hidden on the site.',
        'icon' => 'users',
        'fields' => [
            'facebook_url' => ['label' => 'Facebook Link', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
            'twitter_url' => ['label' => 'Twitter Link', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
            'linkedin_url' => ['label' => 'LinkedIn Link', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
            'instagram_url' => ['label' => 'Instagram Link', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
            'whatsapp_no' => ['label' => 'WhatsApp No', 'type' => 'tel', 'rules' => ['nullable', 'string', 'max:30']],
        ],
    ],

    'urls' => [
        'label' => 'URLs',
        'hint' => 'External destinations linked from the site.',
        'icon' => 'chevron-right',
        'fields' => [
            'product_view_url' => ['label' => 'Product View URL', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
            'feedback_url' => ['label' => 'Feedback URL', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:255']],
        ],
    ],

    'tax' => [
        'label' => 'Tax & Registration',
        'hint' => 'Printed on invoices and other statutory documents.',
        'icon' => 'file',
        'fields' => [
            'registration_no' => ['label' => 'Company Registration No', 'rules' => ['nullable', 'string', 'max:60']],
            'vat_no' => ['label' => 'VAT', 'rules' => ['nullable', 'string', 'max:60']],
            'amount_format' => [
                'label' => 'Amount Format',
                'type' => 'select',
                'options' => [
                    'indian' => 'Indian - 1,00,000.00',
                    'international' => 'International - 100,000.00',
                ],
                'rules' => ['required'],
            ],
        ],
    ],

    'address' => [
        'label' => 'Address',
        'icon' => 'building',
        'fields' => [
            'address_line_1' => [
                'label' => 'Description 1',
                'type' => 'textarea',
                'width' => 'full',
                'hint' => 'Maximum 60 words',
                'rules' => ['nullable', 'string', 'max:400'],
            ],
            'address_line_2' => [
                'label' => 'Description 2',
                'type' => 'textarea',
                'width' => 'full',
                'hint' => 'Maximum 60 words',
                'rules' => ['nullable', 'string', 'max:400'],
            ],
            'city' => ['label' => 'City', 'rules' => ['nullable', 'string', 'max:80']],
            'zip_code' => ['label' => 'Zip Code', 'rules' => ['nullable', 'string', 'max:20']],
            'country' => ['label' => 'Country', 'rules' => ['nullable', 'string', 'max:80']],
            'state' => ['label' => 'State', 'rules' => ['nullable', 'string', 'max:80']],
        ],
    ],

    'general' => [
        'label' => 'General',
        'icon' => 'settings',
        'fields' => [
            'date_format' => [
                'label' => 'Date Format',
                'type' => 'select',
                'options' => [
                    'd-M-Y' => '08-Feb-1977',
                    'd/m/Y' => '08/02/1977',
                    'm/d/Y' => '02/08/1977',
                    'Y-m-d' => '1977-02-08',
                    'd M Y' => '08 Feb 1977',
                ],
                'rules' => ['required'],
            ],
            'operation_hours' => ['label' => 'Operation Hours', 'rules' => ['nullable', 'string', 'max:80']],
            'operation_days' => ['label' => 'Operation Day', 'rules' => ['nullable', 'string', 'max:120']],
            'iframe' => [
                'label' => 'Iframe',
                'type' => 'textarea',
                'width' => 'full',
                'hint' => 'Map or video embed code. Rendered as-is on the contact page.',
                'rules' => ['nullable', 'string', 'max:2000'],
            ],
        ],
    ],

    'seo' => [
        'label' => 'SEO',
        'hint' => 'Titles, descriptions and marketing copy for the public site.',
        'icon' => 'search',
        'fields' => [
            'meta_title_home' => ['label' => 'Homepage Meta Title', 'rules' => ['nullable', 'string', 'max:180']],
            'meta_title_products' => ['label' => 'Products Meta Title', 'rules' => ['nullable', 'string', 'max:180']],
            'meta_description_home' => [
                'label' => 'Homepage Meta Description',
                'type' => 'textarea',
                'width' => 'full',
                'rules' => ['nullable', 'string', 'max:320'],
            ],
            'meta_description_products' => [
                'label' => 'Products Meta Description',
                'type' => 'textarea',
                'width' => 'full',
                'rules' => ['nullable', 'string', 'max:320'],
            ],
            'topbar_offer_text' => ['label' => 'Topbar Offer Text', 'rules' => ['nullable', 'string', 'max:180']],
            'contact_heading' => ['label' => 'Contact Heading', 'rules' => ['nullable', 'string', 'max:180']],
            'demo_form_title' => ['label' => 'Demo Form Title', 'rules' => ['nullable', 'string', 'max:120']],
            'hero_demo_video_url' => [
                'label' => 'Hero Demo Video URL',
                'type' => 'url',
                'hint' => 'YouTube/Vimeo embed URL. Shows a "Watch Demo" button on the homepage hero.',
                'rules' => ['nullable', 'url', 'max:255'],
            ],
            'tawk_property_id' => [
                'label' => 'Tawk.to Property ID',
                'hint' => 'From Tawk.to dashboard → Administration → Chat Widget embed code.',
                'rules' => ['nullable', 'string', 'max:60'],
            ],
            'tawk_widget_id' => [
                'label' => 'Tawk.to Widget ID',
                'hint' => 'Live chat widget stays off the site until both fields are filled in.',
                'rules' => ['nullable', 'string', 'max:60'],
            ],
            'contact_description' => [
                'label' => 'Contact Description',
                'type' => 'textarea',
                'width' => 'full',
                'rules' => ['nullable', 'string', 'max:500'],
            ],
            'contact_card_title' => ['label' => 'Contact Card Title', 'rules' => ['nullable', 'string', 'max:180']],
            'contact_card_description' => [
                'label' => 'Contact Card Description',
                'type' => 'textarea',
                'width' => 'full',
                'rules' => ['nullable', 'string', 'max:500'],
            ],
        ],
    ],

    'media' => [
        'label' => 'Logos & Images',
        'hint' => 'PNG, JPG, WebP or SVG, up to 2 MB each. Leave a picker empty to keep the current file.',
        'icon' => 'package',
        'fields' => [
            'site_logo' => ['label' => 'Site Logo', 'type' => 'image', 'note' => 'Recommended: 200×80px'],
            'favicon' => ['label' => 'Favicon', 'type' => 'image', 'note' => 'Recommended: 32×32px'],
            'company_icon' => ['label' => 'Company Icon', 'type' => 'image', 'note' => 'Recommended: 64×64px'],
            'email_logo' => ['label' => 'Email Logo', 'type' => 'image', 'note' => 'Recommended: 200×60px'],
            'footer_logo' => ['label' => 'Footer Logo', 'type' => 'image', 'note' => 'Recommended: 200×80px'],
        ],
    ],

    'mail' => [
        'label' => 'Mail Configuration',
        'hint' => 'Outgoing mail. Host and credentials saved here override .env at runtime. The mailer itself does not: when MAIL_MAILER is set in .env the environment wins, so a server pinned to "log" cannot be switched to live sending from this screen.',
        'icon' => 'inbox',
        'fields' => [
            'mail_mailer' => [
                'label' => 'Mailer',
                'type' => 'select',
                'options' => [
                    'smtp' => 'SMTP',
                    'sendmail' => 'Sendmail',
                    'mailgun' => 'Mailgun',
                    'ses' => 'Amazon SES',
                    'log' => 'Log (writes to the log file, sends nothing)',
                ],
                'rules' => ['required'],
            ],
            'mail_host' => ['label' => 'Host', 'rules' => ['required_if:mail_mailer,smtp', 'nullable', 'string', 'max:150']],
            'mail_port' => [
                'label' => 'Port',
                'type' => 'number',
                'rules' => ['required_if:mail_mailer,smtp', 'nullable', 'integer', 'between:1,65535'],
            ],
            'mail_username' => ['label' => 'Username', 'rules' => ['nullable', 'string', 'max:150']],
            'mail_password' => ['label' => 'Password', 'type' => 'secret', 'rules' => ['nullable', 'string', 'max:255']],
            'mail_encryption' => [
                'label' => 'Encryption',
                'type' => 'select',
                'options' => ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'],
                'rules' => ['nullable'],
            ],
            'mail_from_address' => ['label' => 'From Address', 'type' => 'email', 'rules' => ['required', 'email', 'max:150']],
            'mail_from_name' => ['label' => 'From Name', 'rules' => ['required', 'string', 'max:150']],
        ],
    ],

    /*
    | Online payment (§11).
    |
    | Here rather than in .env, because the person who has the Razorpay keys is
    | the restaurant's owner and the person who can edit .env is whoever
    | deployed it - and those are not the same person, on the same day, with
    | the same access. A settings screen is the difference between switching
    | payment on in a minute and raising a ticket.
    |
    | The secrets are write-only: the field renders empty however many times
    | it has been saved, and an empty submit keeps what is stored. The screen
    | says "saved" beside one that holds a value, which is the most it can say
    | without handing a secret back to a browser.
    |
    | .env still works and still wins where it is set - see
    | App\Support\PaymentSettings. A deployment that pins its keys in the
    | environment is not overridden by somebody pasting into a form.
    */
    'payments' => [
        'label' => 'Online Payment',
        'hint' => 'Let guests pay from their phone. Leave the provider as "Pay at the counter" and nothing changes.',
        'icon' => 'wallet',
        'fields' => [
            'payment_gateway' => [
                'label' => 'Payment Provider',
                'type' => 'select',
                'options' => [
                    'offline' => 'Pay at the counter (no online payment)',
                    'razorpay' => 'Razorpay',
                ],
                'default' => 'offline',
                'width' => 'full',
                'hint' => 'Until this is set and the keys below are filled in, the guest\'s page says "ask a member of staff" and shows no Pay button.',
            ],

            'razorpay_key' => [
                'label' => 'Razorpay Key ID',
                'rules' => ['nullable', 'string', 'max:120'],
                'placeholder' => 'rzp_live_xxxxxxxxxxxx',
                'hint' => 'From Razorpay Dashboard → Account & Settings → API Keys. Safe to show; it is sent to the browser anyway.',
            ],
            'razorpay_secret' => [
                'label' => 'Razorpay Key Secret',
                'type' => 'secret',
                'rules' => ['nullable', 'string', 'max:255'],
                'hint' => 'Shown once when you generate the key. Signs the payment confirmation.',
            ],

            'razorpay_webhook_secret' => [
                'label' => 'Razorpay Webhook Secret',
                'type' => 'secret',
                'rules' => ['nullable', 'string', 'max:255'],
                'width' => 'full',
                'hint' => 'A DIFFERENT secret from the one above. Set it in Razorpay Dashboard → Settings → Webhooks, on the payment.captured event, pointing at the webhook URL shown on this page. Using the key secret here is the commonest reason a webhook silently never verifies.',
            ],
        ],
    ],

    'integrations' => [
        'label' => 'Integrations',
        'hint' => 'Third-party keys. Secrets are write-only - they are never sent back to the browser.',
        'icon' => 'shield',
        'fields' => [
            'recaptcha_site_key' => ['label' => 'reCAPTCHA Site Key', 'rules' => ['nullable', 'string', 'max:120']],
            'recaptcha_secret_key' => ['label' => 'reCAPTCHA Secret Key', 'type' => 'secret', 'rules' => ['nullable', 'string', 'max:120']],
            'recaptcha_key_type' => [
                'label' => 'reCAPTCHA Key Type',
                'type' => 'select',
                'options' => [
                    'v2_checkbox' => 'v2 Checkbox',
                    'v2_invisible' => 'v2 Invisible',
                    'v3' => 'v3',
                    'v3_invisible' => 'v3 Invisible',
                ],
                'rules' => ['required'],
            ],
            'razorpay_key_id' => ['label' => 'Razorpay Key ID', 'rules' => ['nullable', 'string', 'max:120']],
            'razorpay_key_secret' => ['label' => 'Razorpay Key Secret', 'type' => 'secret', 'rules' => ['nullable', 'string', 'max:120']],
            'razorpay_currency' => [
                'label' => 'Razorpay Currency',
                'type' => 'select',
                'options' => ['INR' => 'INR', 'USD' => 'USD'],
                'rules' => ['required'],
            ],
        ],
    ],

    /*
    | Read through App\Support\ScannerSettings - which is what resolves the
    | three switches below into "is the wireless scanner live" and "is the
    | camera live" - and from there by App\Services\ScannerService,
    | public/assets/js/scanner.js and line-items.js. See those for what each
    | toggle actually changes. Everything defaults on except the two sounds,
    | which a shop floor may not want blaring by default.
    |
    | Both input methods bill through the same cart, the same invoice and the
    | same stock movement; nothing here forks that. They differ only in what
    | turns a physical barcode into a string - a keyboard-wedge scanner, or
    | the phone's camera.
    */
    'scanner' => [
        'label' => 'Barcode Scanner',
        'hint' => 'Wireless scanner and mobile camera behaviour for POS and every screen that scans a product in.',
        'icon' => 'zap',
        'fields' => [
            'scanner_enabled' => [
                'label' => 'Barcode Scanner',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'Master switch. Off, POST /admin/api/scanner/product refuses every scan and neither input method is offered.',
            ],
            'scanner_hardware_input' => [
                'label' => 'Wireless Scanner',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'The dedicated scan field on POS. A 2.4GHz wireless, Bluetooth or wired USB scanner in HID keyboard mode types into it like a keyboard - 1D, 2D and QR alike.',
            ],
            'scanner_camera_enabled' => [
                'label' => 'Mobile Camera Scanner',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'Shows the Scan Barcode button on phones and tablets. The browser asks for camera permission the first time it is used.',
            ],

            /*
            | A preset over the top of the two switches above, because a shop
            | that has just moved its counter to a phone wants one control to
            | say so - not two, in a panel where several other rows are also
            | checkboxes.
            |
            | Deliberately narrowing, never widening: a method is live only
            | when its own switch is on AND this mode admits it. Widening
            | would let a mode silently override a switch someone turned off
            | on purpose. ScannerSettings::wirelessEnabled()/cameraEnabled()
            | are the only places that rule is written down.
            */
            'scanner_mode' => [
                'label' => 'Scanner Mode',
                'type' => 'select',
                'width' => 'full',
                'default' => 'both',
                'options' => [
                    'both' => 'Both - wireless scanner and mobile camera',
                    'wireless' => 'Wireless Scanner only',
                    'camera' => 'Mobile Camera only',
                ],
                'hint' => 'Narrows the two switches above; it cannot turn on a method whose own switch is off.',
                'rules' => ['required'],
            ],

            /*
            | Which symbologies the camera decoder is told to look for. Not
            | cosmetic: html5-qrcode runs one decode pass per enabled format
            | per frame, so a counter that only ever scans EAN-13 shelf labels
            | reads them measurably faster with the 2D formats switched off.
            |
            | A wireless scanner ignores this entirely - it decodes in
            | hardware and hands over a finished string, so which symbologies
            | it reads is configured on the device itself.
            */
            'scanner_formats' => [
                'label' => 'Scanner Type',
                'type' => 'select',
                'width' => 'full',
                'default' => 'all',
                'options' => [
                    'all' => '1D + 2D + QR',
                    '1d' => '1D only - EAN, UPC, Code 128, Code 39, ITF, Codabar',
                    '2d' => '2D + QR - QR, Data Matrix, PDF417, Aztec',
                    'qr' => 'QR only',
                ],
                'hint' => 'Applies to the camera scanner. A wireless scanner decodes on the device, so set its symbologies there.',
                'rules' => ['required'],
            ],

            'scanner_auto_add' => [
                'label' => 'Auto Add Product',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'A resolved scan goes straight into the cart. Off, it fills the search box instead.',
            ],
            'scanner_auto_focus' => [
                'label' => 'Auto Focus',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'Keep the cursor in the scan field between scans.',
            ],

            /*
            | Whether a code that names exactly one product commits itself, or
            | waits to be confirmed. Off, an exact match is shown in the
            | picker like any search result and Enter (or a tap) is what adds
            | it - which is what a counter wants when scans are being read off
            | damaged labels and a wrong line is expensive to unpick.
            |
            | An explicit Enter always commits regardless: this gates the
            | automatic path only, never the operator's own keystroke.
            */
            'scanner_auto_enter' => [
                'label' => 'Auto Enter After Scan',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'An exact barcode match commits without waiting for Enter - for scanners not configured to send one.',
            ],

            'scanner_auto_detect' => [
                'label' => 'Auto Detect Scan',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => "Tell a fast burst of keystrokes ending in Enter apart from someone typing.",
            ],
            'scanner_duplicate_qty_increment' => [
                'label' => 'Duplicate Scan = Qty +1',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'Scanning a product already in the cart bumps its quantity instead of adding a second line.',
            ],
            'scanner_success_sound' => [
                'label' => 'Success Sound',
                'type' => 'checkbox',
                'default' => '0',
            ],
            'scanner_error_sound' => [
                'label' => 'Error Sound',
                'type' => 'checkbox',
                'default' => '0',
            ],

            /*
            | The phone-side half of "Sound / Vibration". Defaults on because
            | it is the only scan feedback that survives a noisy shop floor
            | with the handset on silent, and it costs nothing on a device
            | without a vibrator - navigator.vibrate is simply absent there.
            */
            'scanner_vibrate' => [
                'label' => 'Vibration',
                'type' => 'checkbox',
                'default' => '1',
                'hint' => 'Buzz the handset on a scan. Phones and tablets only; desktop browsers have no vibrator to call.',
            ],

            'scanner_search_by' => [
                'label' => 'Search By',
                'type' => 'select',
                'width' => 'full',
                'default' => 'barcode_sku',
                'options' => [
                    'barcode_sku' => 'Barcode + SKU',
                    'barcode' => 'Barcode Only',
                    'sku' => 'SKU Only',
                ],
                'rules' => ['required'],
            ],
        ],
    ],

];
