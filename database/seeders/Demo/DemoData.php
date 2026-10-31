<?php

namespace Database\Seeders\Demo;

/**
 * Plain data for the demo companies: menus, ingredients, recipes, people.
 *
 * Kept apart from the seeder so the seeder reads as "what happens" and this
 * file reads as "what a real restaurant sells". Prices are in rupees, GST
 * is the rate in percent, quantities are in the item's own unit.
 *
 * Item shape: [name, price, food, gst, kind, options]
 *   kind    made   cooked or poured to order, moves no stock when sold
 *           stock  bought in and sold as it is, tracked on the shelf
 *   options cost, qty (opening stock), reorder, unit, variants, mods, tag, desc
 */
class DemoData
{
    /** Indian first/last names for customers and staff. */
    public const FIRST = ['Aarav', 'Vivaan', 'Aditya', 'Arjun', 'Rohan', 'Karan', 'Rahul', 'Sameer', 'Nikhil', 'Varun',
        'Ananya', 'Diya', 'Ishita', 'Kavya', 'Meera', 'Neha', 'Pooja', 'Priya', 'Riya', 'Sneha', 'Tanvi', 'Isha', 'Aditi', 'Simran'];

    public const LAST = ['Sharma', 'Verma', 'Gupta', 'Patel', 'Mehta', 'Reddy', 'Nair', 'Iyer', 'Kulkarni', 'Joshi',
        'Desai', 'Bhatia', 'Kapoor', 'Singh', 'Khan', 'Rao', 'Pillai', 'Saxena', 'Chopra', 'Menon'];

    /** @return array<string, array<int, array>> category => items */
    public static function cafe(): array
    {
        return [
            'Hot Beverages' => [
                ['Masala Chai', 30, 'veg', 5, 'made', ['cost' => 8, 'tag' => 'bestseller', 'desc' => 'Strong ginger-cardamom tea, simmered with milk.']],
                ['Ginger Chai', 35, 'veg', 5, 'made', ['cost' => 9]],
                ['Kulhad Chai', 40, 'veg', 5, 'made', ['cost' => 11, 'desc' => 'Served in a clay kulhad.']],
                ['Filter Coffee', 55, 'veg', 5, 'made', ['cost' => 14]],
                ['Cappuccino', 120, 'veg', 5, 'made', ['cost' => 34]],
                ['Hot Chocolate', 140, 'veg', 5, 'made', ['cost' => 42]],
                ['Green Tea', 60, 'vegan', 5, 'made', ['cost' => 12]],
            ],
            'Cold Beverages' => [
                ['Cold Coffee', 130, 'veg', 5, 'made', ['cost' => 38, 'tag' => 'bestseller']],
                ['Iced Lemon Tea', 90, 'veg', 5, 'made', ['cost' => 22]],
                ['Fresh Lime Soda', 70, 'veg', 5, 'made', ['cost' => 14]],
                ['Mango Shake', 120, 'veg', 5, 'made', ['cost' => 36]],
                ['Oreo Shake', 140, 'veg', 5, 'made', ['cost' => 44]],
            ],
            'Snacks' => [
                ['Veg Samosa (2 pcs)', 40, 'veg', 5, 'made', ['cost' => 12, 'tag' => 'bestseller']],
                ['Bread Pakoda', 35, 'veg', 5, 'made', ['cost' => 10]],
                ['Veg Sandwich', 80, 'veg', 5, 'made', ['cost' => 24]],
                ['Grilled Cheese Sandwich', 110, 'veg', 5, 'made', ['cost' => 38]],
                ['Maggi Masala', 70, 'veg', 5, 'made', ['cost' => 18]],
                ['Paneer Puff', 60, 'veg', 5, 'stock', ['cost' => 32, 'qty' => 36, 'reorder' => 10]],
                ['Chicken Puff', 70, 'non_veg', 5, 'stock', ['cost' => 38, 'qty' => 30, 'reorder' => 10]],
            ],
            'Bakery' => [
                ['Chocolate Muffin', 60, 'egg', 5, 'stock', ['cost' => 28, 'qty' => 40, 'reorder' => 12]],
                ['Blueberry Muffin', 70, 'egg', 5, 'stock', ['cost' => 31, 'qty' => 34, 'reorder' => 12]],
                ['Butter Croissant', 80, 'egg', 5, 'stock', ['cost' => 36, 'qty' => 24, 'reorder' => 8]],
                ['Cheese Cake Slice', 150, 'egg', 5, 'stock', ['cost' => 66, 'qty' => 5, 'reorder' => 8]],
                ['Fudge Brownie', 90, 'egg', 5, 'stock', ['cost' => 38, 'qty' => 28, 'reorder' => 10]],
                ['Choco Chip Cookies Pack', 50, 'egg', 5, 'stock', ['cost' => 24, 'qty' => 45, 'reorder' => 15]],
            ],
            'Packaged Items' => [
                ['Bisleri Water 1L', 20, 'veg', 18, 'stock', ['cost' => 12, 'qty' => 120, 'reorder' => 40, 'mrp' => 20]],
                ['Coca-Cola 300ml', 40, 'veg', 28, 'stock', ['cost' => 28, 'qty' => 72, 'reorder' => 24, 'mrp' => 40]],
                ['Sprite 300ml', 40, 'veg', 28, 'stock', ['cost' => 28, 'qty' => 60, 'reorder' => 24, 'mrp' => 40]],
                ['Lays Classic Salted', 20, 'veg', 12, 'stock', ['cost' => 15, 'qty' => 90, 'reorder' => 30, 'mrp' => 20]],
                ['Kurkure Masala Munch', 20, 'veg', 12, 'stock', ['cost' => 15, 'qty' => 80, 'reorder' => 30, 'mrp' => 20]],
                ['Parle-G Biscuit', 10, 'veg', 12, 'stock', ['cost' => 8, 'qty' => 150, 'reorder' => 50, 'mrp' => 10]],
                ['Dark Fantasy Choco Fills', 35, 'veg', 18, 'stock', ['cost' => 26, 'qty' => 6, 'reorder' => 20, 'mrp' => 35]],
                ["Haldiram's Aloo Bhujia", 50, 'veg', 12, 'stock', ['cost' => 38, 'qty' => 48, 'reorder' => 15, 'mrp' => 50]],
            ],
        ];
    }

    /** @return array<string, array<int, array>> */
    public static function restaurant(): array
    {
        $spice = ['mods' => ['Spice level']];
        $extras = ['mods' => ['Add extras']];

        return [
            'Starters' => [
                ['Paneer Tikka', 180, 'veg', 5, 'made', ['cost' => 70, 'station' => 'Tandoor', 'tag' => 'bestseller', 'desc' => 'Cottage cheese marinated in hung curd and spices, cooked in the tandoor.', 'variants' => [['Half', 180], ['Full', 320]]] + $spice],
                ['Chicken Tikka', 220, 'non_veg', 5, 'made', ['cost' => 85, 'station' => 'Tandoor', 'tag' => 'bestseller', 'variants' => [['Half', 220], ['Full', 400]]] + $spice],
                ['Tandoori Chicken', 280, 'non_veg', 5, 'made', ['cost' => 110, 'station' => 'Tandoor', 'variants' => [['Half', 280], ['Full', 520]]] + $spice],
                ['Veg Seekh Kebab', 240, 'veg', 5, 'made', ['cost' => 82, 'station' => 'Tandoor']],
                ['Hara Bhara Kebab', 210, 'veg', 5, 'made', ['cost' => 70, 'station' => 'Tandoor']],
                ['Chilli Chicken', 260, 'non_veg', 5, 'made', ['cost' => 96] + $spice],
                ['Veg Manchurian', 210, 'veg', 5, 'made', ['cost' => 68]],
            ],
            'Main Course' => [
                ['Dal Makhani', 260, 'veg', 5, 'made', ['cost' => 72, 'tag' => 'bestseller', 'desc' => 'Black lentils slow-cooked overnight with butter and cream.']],
                ['Paneer Butter Masala', 290, 'veg', 5, 'made', ['cost' => 105, 'tag' => 'bestseller'] + $spice],
                ['Kadai Paneer', 280, 'veg', 5, 'made', ['cost' => 100] + $spice],
                ['Palak Paneer', 270, 'veg', 5, 'made', ['cost' => 96]],
                ['Veg Kolhapuri', 250, 'veg', 5, 'made', ['cost' => 82] + $spice],
                ['Butter Chicken', 320, 'non_veg', 5, 'made', ['cost' => 118, 'tag' => 'bestseller', 'variants' => [['Half', 320], ['Full', 580]]] + $spice],
                ['Chicken Curry', 300, 'non_veg', 5, 'made', ['cost' => 108] + $spice],
                ['Mutton Rogan Josh', 420, 'non_veg', 5, 'made', ['cost' => 190] + $spice],
            ],
            'Rice & Biryani' => [
                ['Veg Biryani', 190, 'veg', 5, 'made', ['cost' => 62, 'variants' => [['Half', 190], ['Full', 320]]]],
                ['Chicken Biryani', 230, 'non_veg', 5, 'made', ['cost' => 88, 'tag' => 'bestseller', 'variants' => [['Half', 230], ['Full', 400]]]],
                ['Jeera Rice', 140, 'veg', 5, 'made', ['cost' => 36]],
                ['Steamed Rice', 110, 'veg', 5, 'made', ['cost' => 28]],
                ['Veg Fried Rice', 180, 'veg', 5, 'made', ['cost' => 52]],
                ['Hakka Noodles', 190, 'veg', 5, 'made', ['cost' => 54]],
            ],
            'Breads' => [
                ['Butter Naan', 55, 'veg', 5, 'made', ['cost' => 14, 'station' => 'Tandoor'] + $extras],
                ['Garlic Naan', 65, 'veg', 5, 'made', ['cost' => 17, 'station' => 'Tandoor'] + $extras],
                ['Tandoori Roti', 25, 'veg', 5, 'made', ['cost' => 7, 'station' => 'Tandoor']],
                ['Lachha Paratha', 60, 'veg', 5, 'made', ['cost' => 16, 'station' => 'Tandoor']],
            ],
            'Desserts' => [
                ['Gulab Jamun (2 pcs)', 90, 'veg', 5, 'made', ['cost' => 26, 'station' => 'Bar & Desserts']],
                ['Rasmalai (2 pcs)', 110, 'veg', 5, 'made', ['cost' => 34, 'station' => 'Bar & Desserts']],
                ['Brownie with Ice Cream', 160, 'egg', 5, 'made', ['cost' => 58, 'station' => 'Bar & Desserts']],
            ],
            'Beverages' => [
                ['Fresh Lime Soda', 80, 'veg', 5, 'made', ['cost' => 16, 'station' => 'Bar & Desserts']],
                ['Masala Chaas', 60, 'veg', 5, 'made', ['cost' => 14, 'station' => 'Bar & Desserts']],
                ['Sweet Lassi', 90, 'veg', 5, 'made', ['cost' => 26, 'station' => 'Bar & Desserts']],
                ['Cold Coffee', 120, 'veg', 5, 'made', ['cost' => 36, 'station' => 'Bar & Desserts']],
                ['Soft Drink 300ml', 50, 'veg', 28, 'stock', ['cost' => 30, 'qty' => 96, 'reorder' => 36, 'mrp' => 50, 'station' => 'Bar & Desserts']],
                ['Mineral Water 1L', 20, 'veg', 18, 'stock', ['cost' => 12, 'qty' => 140, 'reorder' => 48, 'mrp' => 20, 'station' => 'Bar & Desserts']],
            ],
        ];
    }

    /** @return array<string, array<int, array>> */
    public static function chain(): array
    {
        $size = fn (int $r, int $l) => [['Regular', $r], ['Large', $l]];

        return [
            'Burgers' => [
                ['Classic Veg Burger', 99, 'veg', 5, 'made', ['cost' => 34, 'tag' => 'bestseller', 'desc' => 'Crisp veg patty, lettuce, tomato and our house mayo.']],
                ['Cheese Veg Burger', 129, 'veg', 5, 'made', ['cost' => 44]],
                ['Paneer Tikka Burger', 149, 'veg', 5, 'made', ['cost' => 52]],
                ['Crispy Chicken Burger', 159, 'non_veg', 5, 'made', ['cost' => 58, 'tag' => 'bestseller']],
                ['Double Chicken Burger', 219, 'non_veg', 5, 'made', ['cost' => 84]],
            ],
            'Wraps & Rolls' => [
                ['Paneer Tikka Wrap', 139, 'veg', 5, 'made', ['cost' => 48]],
                ['Peri Peri Chicken Wrap', 159, 'non_veg', 5, 'made', ['cost' => 56]],
                ['Veg Kathi Roll', 109, 'veg', 5, 'made', ['cost' => 36]],
                ['Chicken Kathi Roll', 139, 'non_veg', 5, 'made', ['cost' => 48]],
            ],
            'Rice Bowls' => [
                ['Paneer Makhani Rice Bowl', 189, 'veg', 5, 'made', ['cost' => 66]],
                ['Butter Chicken Rice Bowl', 219, 'non_veg', 5, 'made', ['cost' => 80, 'tag' => 'bestseller']],
                ['Veg Schezwan Rice Bowl', 169, 'veg', 5, 'made', ['cost' => 58]],
            ],
            'Sides' => [
                ['French Fries', 89, 'veg', 5, 'made', ['cost' => 24, 'variants' => $size(89, 129), 'tag' => 'bestseller']],
                ['Peri Peri Fries', 109, 'veg', 5, 'made', ['cost' => 30, 'variants' => $size(109, 149)]],
                ['Chicken Nuggets (6 pcs)', 149, 'non_veg', 5, 'made', ['cost' => 52]],
                ['Garlic Bread', 99, 'veg', 5, 'made', ['cost' => 30]],
            ],
            'Shakes & Beverages' => [
                ['Chocolate Shake', 139, 'veg', 5, 'made', ['cost' => 42, 'variants' => $size(139, 189)]],
                ['Mango Shake', 129, 'veg', 5, 'made', ['cost' => 38, 'variants' => $size(129, 179)]],
                ['Cold Coffee', 119, 'veg', 5, 'made', ['cost' => 34]],
                ['Fresh Lime Soda', 69, 'veg', 5, 'made', ['cost' => 14]],
                ['Pepsi 300ml', 40, 'veg', 28, 'stock', ['cost' => 28, 'qty' => 120, 'reorder' => 48, 'mrp' => 40]],
                ['Mineral Water 1L', 20, 'veg', 18, 'stock', ['cost' => 12, 'qty' => 160, 'reorder' => 60, 'mrp' => 20]],
            ],
            'Desserts' => [
                ['Choco Lava Cake', 99, 'egg', 5, 'stock', ['cost' => 42, 'qty' => 40, 'reorder' => 12]],
                ['Vanilla Ice Cream Cup', 79, 'veg', 18, 'stock', ['cost' => 34, 'qty' => 60, 'reorder' => 20, 'mrp' => 79]],
                ['Brownie Sundae', 149, 'egg', 5, 'made', ['cost' => 54]],
            ],
        ];
    }

    /** Raw materials: [name, unit, cost per unit, opening qty, reorder, shelf life days or null]. */
    public static function restaurantIngredients(): array
    {
        return [
            ['Paneer', 'KG', 320, 18, 6, 6], ['Chicken', 'KG', 190, 40, 12, 3], ['Mutton', 'KG', 700, 10, 4, 3],
            ['Basmati Rice', 'KG', 95, 80, 25, null], ['Wheat Flour', 'KG', 38, 60, 20, null], ['Butter', 'KG', 520, 12, 4, 25],
            ['Fresh Cream', 'LTR', 240, 10, 4, 7], ['Tomato', 'KG', 30, 25, 10, null], ['Onion', 'KG', 28, 45, 15, null],
            ['Cooking Oil', 'LTR', 130, 40, 12, null], ['Garam Masala Mix', 'KG', 450, 6, 2, null], ['Curd', 'KG', 70, 22, 8, 5],
            ['Milk', 'LTR', 56, 30, 10, 3], ['Sugar', 'KG', 44, 30, 10, null], ['Black Lentils (Urad)', 'KG', 120, 14, 5, null],
            ['Hakka Noodles Raw', 'KG', 90, 14, 5, null], ['Garlic', 'KG', 160, 5, 2, null],
        ];
    }

    /**
     * Recipes: dish => [variant or '' => [[ingredient, qty], ...]].
     * A variant row replaces the generic one for that size.
     */
    public static function restaurantRecipes(): array
    {
        $x = fn (float $f, array $rows) => array_map(fn ($r) => [$r[0], round($r[1] * $f, 4)], $rows);
        $paneerTikka = [['Paneer', .12], ['Curd', .03], ['Garam Masala Mix', .01], ['Cooking Oil', .01]];
        $chickenTikka = [['Chicken', .15], ['Curd', .03], ['Garam Masala Mix', .01], ['Cooking Oil', .01]];
        $butterChicken = [['Chicken', .18], ['Butter', .02], ['Fresh Cream', .03], ['Tomato', .10], ['Garam Masala Mix', .01]];
        $vegBiryani = [['Basmati Rice', .15], ['Onion', .05], ['Garam Masala Mix', .01], ['Cooking Oil', .02], ['Curd', .03]];
        $chickBiryani = [['Basmati Rice', .15], ['Chicken', .18], ['Onion', .05], ['Garam Masala Mix', .01], ['Curd', .03], ['Cooking Oil', .02]];

        return [
            'Paneer Tikka' => ['Half' => $paneerTikka, 'Full' => $x(2, $paneerTikka)],
            'Chicken Tikka' => ['Half' => $chickenTikka, 'Full' => $x(2, $chickenTikka)],
            'Butter Chicken' => ['Half' => $butterChicken, 'Full' => $x(2, $butterChicken)],
            'Veg Biryani' => ['Half' => $vegBiryani, 'Full' => $x(2, $vegBiryani)],
            'Chicken Biryani' => ['Half' => $chickBiryani, 'Full' => $x(2, $chickBiryani)],
            'Paneer Butter Masala' => ['' => [['Paneer', .15], ['Butter', .02], ['Fresh Cream', .04], ['Tomato', .12], ['Onion', .05], ['Garam Masala Mix', .01]]],
            'Dal Makhani' => ['' => [['Black Lentils (Urad)', .08], ['Butter', .02], ['Fresh Cream', .03], ['Tomato', .06]]],
            'Butter Naan' => ['' => [['Wheat Flour', .09], ['Butter', .01], ['Curd', .01]]],
            'Tandoori Roti' => ['' => [['Wheat Flour', .06]]],
            'Hakka Noodles' => ['' => [['Hakka Noodles Raw', .15], ['Cooking Oil', .02], ['Onion', .03]]],
            'Sweet Lassi' => ['' => [['Curd', .2], ['Sugar', .02]]],
            'Gulab Jamun (2 pcs)' => ['' => [['Milk', .1], ['Sugar', .05], ['Wheat Flour', .01]]],
        ];
    }

    public static function chainIngredients(): array
    {
        return [
            ['Burger Bun', 'PCS', 7, 300, 100, 4], ['Veg Patty', 'PCS', 14, 220, 80, 60], ['Chicken Patty', 'PCS', 24, 200, 80, 60],
            ['Cheese Slice', 'PCS', 6, 260, 90, 30], ['Lettuce', 'KG', 90, 12, 5, 4], ['Potato', 'KG', 26, 80, 30, null],
            ['Cooking Oil', 'LTR', 130, 50, 18, null], ['Tortilla Wrap', 'PCS', 6, 240, 80, 20], ['Paneer', 'KG', 320, 14, 5, 6],
            ['Basmati Rice', 'KG', 95, 60, 20, null], ['Milk', 'LTR', 56, 40, 14, 3], ['Chocolate Syrup', 'LTR', 210, 10, 4, null],
        ];
    }

    public static function chainRecipes(): array
    {
        return [
            'Classic Veg Burger' => ['' => [['Burger Bun', 1], ['Veg Patty', 1], ['Lettuce', .02]]],
            'Cheese Veg Burger' => ['' => [['Burger Bun', 1], ['Veg Patty', 1], ['Cheese Slice', 1], ['Lettuce', .02]]],
            'Crispy Chicken Burger' => ['' => [['Burger Bun', 1], ['Chicken Patty', 1], ['Lettuce', .02], ['Cooking Oil', .03]]],
            'Double Chicken Burger' => ['' => [['Burger Bun', 1], ['Chicken Patty', 2], ['Cheese Slice', 1], ['Lettuce', .02]]],
            'French Fries' => ['Regular' => [['Potato', .15], ['Cooking Oil', .03]], 'Large' => [['Potato', .25], ['Cooking Oil', .05]]],
            'Paneer Tikka Wrap' => ['' => [['Tortilla Wrap', 1], ['Paneer', .08], ['Lettuce', .02]]],
            'Chocolate Shake' => ['Regular' => [['Milk', .25], ['Chocolate Syrup', .03]], 'Large' => [['Milk', .4], ['Chocolate Syrup', .05]]],
            'Mango Shake' => ['Regular' => [['Milk', .25]], 'Large' => [['Milk', .4]]],
        ];
    }

    /** @return array<string, array<int, array>> modifier name => [min, max, [[option, price], ...]] */
    public static function modifiers(string $kind): array
    {
        $common = [
            'Spice level' => [1, 1, [['Mild', 0], ['Medium', 0], ['Spicy', 0], ['Extra spicy', 0]]],
            'Add extras' => [0, 3, [['Extra butter', 20], ['Extra cheese', 30], ['Raita', 40], ['Papad', 15]]],
        ];

        return $kind === 'restaurant' ? $common : [];
    }

    public static function suppliers(string $kind): array
    {
        return match ($kind) {
            'cafe' => [
                ['Sri Lakshmi Bakers', 'Murugan R', 'Bengaluru', 'Karnataka', 7], ['Metro Cash & Carry Wholesale', 'Accounts Desk', 'Bengaluru', 'Karnataka', 15],
                ['Nandini Dairy Distributors', 'Harish Gowda', 'Bengaluru', 'Karnataka', 3],
            ],
            'restaurant' => [
                ['Shree Ganesh Dairy & Paneer', 'Ramesh Patil', 'Pune', 'Maharashtra', 7], ['Fresh Farm Vegetables', 'Sunil Jadhav', 'Pune', 'Maharashtra', 3],
                ['Al-Noor Meat Suppliers', 'Imran Shaikh', 'Pune', 'Maharashtra', 2], ['Kisan Grains & Spices', 'Dinesh Agarwal', 'Pune', 'Maharashtra', 15],
                ['Coca-Cola Beverages Distributor', 'Accounts Desk', 'Pune', 'Maharashtra', 10],
            ],
            default => [
                ['Metro Cash & Carry Wholesale', 'Accounts Desk', null, null, 15], ['Frozen Foods India Pvt Ltd', 'Vikas Malhotra', null, null, 10],
                ['Fresh Basket Produce', 'Anil Kumar', null, null, 3], ['PepsiCo Authorised Dealer', 'Accounts Desk', null, null, 10],
            ],
        };
    }
}
