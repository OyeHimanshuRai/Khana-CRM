<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Shop $shop): View
    {
        $orders = Order::forShop($shop->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->with('items')
            ->latest('placed_at')
            ->paginate(15);

        return view('shop.orders.index', compact('orders'));
    }

    public function show(Shop $shop, int $order): View
    {
        $order = Order::forShop($shop->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->with(['items', 'coupon'])
            ->findOrFail($order);

        return view('shop.orders.show', compact('order'));
    }
}
