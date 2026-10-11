<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Customer;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * The people the shop sells to. Suppliers belong to ERP now.
 *
 * Most customers are in the shop's own state so their GST splits into
 * CGST+SGST; a few are not, so the IGST column and the inter-state tax
 * report have something in them. Balances are left at zero here on purpose -
 * what a customer owes is the sum of their ledger, and DemoSalesSeeder
 * writes that by actually billing them rather than by typing a number into
 * the balance column.
 */
class DemoPartySeeder extends Seeder
{
    use SeedsDemoData;

    /** Villages around Jaipur, so the address fields read like a real book. */
    private const VILLAGES = [
        ['Chomu', 'Chomu', 'Jaipur', '303702'],
        ['Bassi', 'Bassi', 'Jaipur', '303301'],
        ['Phulera', 'Phulera', 'Jaipur', '303338'],
        ['Sambhar', 'Phulera', 'Jaipur', '303604'],
        ['Dudu', 'Dudu', 'Jaipur', '303008'],
        ['Shahpura', 'Shahpura', 'Jaipur', '303103'],
        ['Viratnagar', 'Viratnagar', 'Jaipur', '303102'],
        ['Kotputli', 'Kotputli', 'Jaipur', '303108'],
        ['Jamwa Ramgarh', 'Jamwa Ramgarh', 'Jaipur', '303109'],
        ['Amber', 'Amber', 'Jaipur', '302028'],
    ];

    public function run(): void
    {
        $this->seedRandom(2);

        $this->customers();
    }

    /**
     * Twenty customers across the four types the app knows about.
     *
     * Roughly half may buy on credit, because the dues, ledger and reminder
     * modules are all about the ones who do - a book of cash-only walk-ins
     * leaves those three screens empty.
     */
    private function customers(): void
    {
        if ($this->alreadySeeded('Customers', Customer::query()->count())) {
            return;
        }

        $shopId = CurrentShop::id();

        $first = ['Ramesh', 'Sunita', 'Mahendra', 'Kailash', 'Pooja', 'Devilal', 'Shanti', 'Gopal',
            'Bhanwar', 'Radha', 'Hariram', 'Meena', 'Jagdish', 'Laxmi', 'Prakash', 'Kavita',
            'Mohan', 'Sarita', 'Naresh', 'Geeta'];

        $last = ['Choudhary', 'Meena', 'Saini', 'Jat', 'Gurjar', 'Sharma', 'Yadav', 'Kumawat',
            'Verma', 'Bairwa'];

        $crops = ['Wheat, Mustard', 'Bajra, Guar', 'Cotton, Chilli', 'Paddy, Gram',
            'Groundnut, Soybean', 'Vegetables', 'Mustard, Barley', 'Maize, Moong'];

        // Two out-of-state buyers, so IGST appears on the tax report.
        $outOfState = [
            12 => ['Gujarat', 'Palanpur', '385001'],
            17 => ['Madhya Pradesh', 'Neemuch', '458441'],
        ];

        for ($i = 0; $i < self::PER_MODULE; $i++) {
            $name = $first[$i].' '.$last[$i % count($last)];
            [$village, $taluka, $district, $pincode] = self::VILLAGES[$i % count(self::VILLAGES)];

            $state = 'Rajasthan';
            $city = $district;

            if (isset($outOfState[$i])) {
                [$state, $city, $pincode] = $outOfState[$i];
                $village = $city;
                $taluka = $city;
                $district = $city;
            }

            $type = $this->pick(['farmer', 'farmer', 'farmer', 'retail', 'wholesale', 'dealer']);

            /*
             | Most of the book is on account, which is what an agri retailer
             | actually looks like - the crop is sold months after the inputs
             | are bought. It is also what gives the dues, ledger, ageing and
             | reminder modules anything to show: a cash-only book leaves all
             | four of them empty.
             |
             | Two are kept cash-only so the "this customer cannot take
             | credit" refusal is reachable at the till.
             */
            $onCredit = $i >= 2;

            $customer = Customer::query()->create([
                'shop_id' => $shopId,
                'code' => 'CUS'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'mobile' => '9'.$this->between(100000000, 999999999),
                'email' => strtolower(str_replace(' ', '.', $name)).$i.'@customer.test',
                'address_line1' => $this->between(1, 120).', '.$this->pick(['Main Bazaar', 'Ward No 4', 'Krishi Colony', 'Near Bus Stand']),
                'village' => $village,
                'taluka' => $taluka,
                'district' => $district,
                'city' => $city,
                'state' => $state,
                'pincode' => $pincode,
                // Decimal acres, not free text - the column is decimal(10,2)
                // and the reports average it.
                'land_area' => $type === 'farmer' ? $this->money(1.5, 42) : null,
                'primary_crops' => $type === 'farmer' ? $this->pick($crops) : null,
                'type' => $type,
                'gstin' => in_array($type, ['wholesale', 'dealer'], true)
                    ? ($state === 'Rajasthan' ? '08' : '24').'AAACU'.$this->between(1000, 9999).'B1Z'.($i % 10)
                    : null,
                'allow_credit' => $onCredit,
                // Limits a season's inputs would actually fit inside. Too
                // tight and every second sale is refused at the till, which
                // makes for a shop with no sales rather than a careful one.
                'credit_limit' => $onCredit
                    ? $this->pick([80000, 120000, 150000, 200000, 300000])
                    : 0,
                'credit_days' => $onCredit ? $this->pick([7, 15, 21, 30, 45]) : 0,
                'is_active' => $i < 19,
            ]);

            $this->addressBook($customer);
        }

        $this->say(sprintf('%d customers.', self::PER_MODULE));
    }

    /**
     * A delivery address or two, for the storefront's checkout.
     *
     * Only for some customers: an address book that is always full is not
     * what a real one looks like, and the "no saved address" branch of
     * checkout deserves to be reachable too.
     */
    private function addressBook(Customer $customer): void
    {
        if (! $this->chance(70)) {
            return;
        }

        $count = $this->chance(30) ? 2 : 1;

        for ($n = 0; $n < $count; $n++) {
            Address::query()->create([
                'shop_id' => $customer->shop_id,
                'customer_id' => $customer->id,
                'label' => $n === 0 ? 'Home' : $this->pick(['Farm', 'Shop', 'Godown']),
                'recipient_name' => $customer->name,
                'mobile' => $customer->mobile,
                'address_line1' => $customer->address_line1,
                'village' => $customer->village,
                'taluka' => $customer->taluka,
                'district' => $customer->district,
                'city' => $customer->city,
                'state' => $customer->state,
                'pincode' => $customer->pincode,
                'is_default' => $n === 0,
            ]);
        }
    }
}
