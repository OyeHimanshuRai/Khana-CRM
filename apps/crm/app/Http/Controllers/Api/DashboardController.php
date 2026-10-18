<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Admin\DashboardController as WebDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

// The owner's home screen in the Partner app: the web dashboard's own figures, each still behind its permission.
class DashboardController extends Controller
{
    public function __invoke(WebDashboard $web): JsonResponse
    {
        $data = $web->data();

        return response()->json([
            'shop' => $data['shop'] === null ? null : ['id' => $data['shop']->id, 'name' => $data['shop']->name],
            'sales' => $data['sales'],
            'profit' => $data['profit'],
            'room' => $data['room'],
            'kitchen' => $data['kitchen'],
            'dues' => $data['dues'],
            'payments' => $data['payments'],
            'sold_out' => $data['erp']['sold_out'] ?? [],
            'alerts' => collect($data['alerts'])->map(fn (array $alert) => [
                'tone' => $alert['tone'],
                'title' => $alert['title'],
            ])->values(),
        ]);
    }
}
