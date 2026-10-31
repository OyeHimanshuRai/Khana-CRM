<?php

/*
|--------------------------------------------------------------------------
| Tally export
|--------------------------------------------------------------------------
|
| The names the exported vouchers post to, and the Tally groups those
| ledgers are created under. App\Services\TallyExportService reads this.
|
| A ledger name has to match the restaurant's Tally company character for
| character, or Tally creates a second ledger beside the one the accountant
| already uses. These are the names most Indian CAs set up by default; one
| that differs is a line here, not a code change.
|
| The groups are Tally's own reserved names (Sundry Debtors, Duties & Taxes
| and so on). Those are the same in every Tally company and should not be
| renamed.
|
*/

return [

    'ledgers' => [
        // Who owes and who is owed, when there is nobody named.
        'walk_in' => 'Walk-in Customers',

        // Where the money went in or came out, per Payment::METHODS key.
        'methods' => [
            'cash' => 'Cash',
            'upi' => 'UPI Collections',
            'card' => 'Card Collections',
            'bank' => 'Bank Account',
            'cheque' => 'Bank Account',
            'wallet' => 'Wallet Collections',
        ],

        // `%s` is the GST rate: "Sales @ 5%".
        'sales' => 'Sales @ %s%%',
        'purchase' => 'Purchase @ %s%%',

        'output_cgst' => 'Output CGST',
        'output_sgst' => 'Output SGST',
        'output_igst' => 'Output IGST',
        'output_cess' => 'Output Cess',
        'input_cgst' => 'Input CGST',
        'input_sgst' => 'Input SGST',
        'input_igst' => 'Input IGST',

        'round_off' => 'Round Off',
        'freight' => 'Freight & Other Charges',

        // Expenses with no category still have to post somewhere.
        'expense_fallback' => 'General Expenses',
    ],

    /*
    | Tally group for each kind of ledger the export creates.
    */
    'groups' => [
        'debtor' => 'Sundry Debtors',
        'creditor' => 'Sundry Creditors',
        'cash' => 'Cash-in-Hand',
        'bank' => 'Bank Accounts',
        'sales' => 'Sales Accounts',
        'purchase' => 'Purchase Accounts',
        'duty' => 'Duties & Taxes',
        'indirect_expense' => 'Indirect Expenses',
        'direct_expense' => 'Direct Expenses',
    ],

];
