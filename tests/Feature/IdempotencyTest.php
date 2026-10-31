<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureIdempotent;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Do this once", however many times the request arrives.
 *
 * Restock is the vehicle because it is the simplest route that carries the
 * middleware and whose effect is easy to count. What is being proved is the
 * middleware: the same key replays the first answer and runs nothing twice, a
 * different body under the same key is refused, a request that did nothing is
 * not remembered, and a request with no key behaves exactly as before.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function item(): Product
    {
        $product = new Product([
            'name' => 'Cheese Cake Slice',
            'slug' => Product::uniqueSlug('Cheese Cake Slice'),
            'sku' => Product::generateSku('CAKE'),
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => 55,
            'selling_price' => 140,
            'is_active' => true,
            'is_made_to_order' => false,
        ]);
        $product->save();

        return $product;
    }

    private function staff(): User
    {
        $user = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Baker',
            'email' => 'baker@example.test',
            'password' => 'baker-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(['dashboard.overview.view', 'inventory.stock.restock']);

        return $user->fresh();
    }

    private function onHand(Product $product): float
    {
        return (float) ProductStock::allShops()->where('product_id', $product->id)->sum('quantity');
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'warehouse_id' => Warehouse::allShops()->where('shop_id', $this->shop()->id)->value('id'),
            'quantity' => 12,
        ], $over);
    }

    public function test_the_same_key_twice_is_one_restock_and_the_same_answer(): void
    {
        $cake = $this->item();
        $this->actingAs($this->staff());
        $key = 'attempt-'.str_repeat('a', 20);

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body())
            ->assertOk();

        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body())
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(12.0, $this->onHand($cake), 'the second press must not add another twelve');
        $this->assertSame(1, StockMovement::allShops()->where('product_id', $cake->id)->count());
        $this->assertSame($first->getContent(), $second->getContent());
    }

    public function test_without_a_key_nothing_changes(): void
    {
        $cake = $this->item();
        $this->actingAs($this->staff());

        $this->postJson(route('admin.stock.restock', $cake), $this->body())->assertOk();
        $this->postJson(route('admin.stock.restock', $cake), $this->body())->assertOk();

        $this->assertSame(24.0, $this->onHand($cake));
    }

    public function test_the_same_key_with_a_different_request_is_refused(): void
    {
        $cake = $this->item();
        $this->actingAs($this->staff());
        $key = 'attempt-'.str_repeat('b', 20);

        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body(['quantity' => 12]))
            ->assertOk();

        // Quietly handing back the first answer would hide a client that is reusing a key.
        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body(['quantity' => 99]))
            ->assertStatus(422);

        $this->assertSame(12.0, $this->onHand($cake));
    }

    public function test_a_request_that_did_nothing_is_not_remembered(): void
    {
        $cake = $this->item();
        $this->actingAs($this->staff());
        $key = 'attempt-'.str_repeat('c', 20);

        // Refused (a quantity of nothing): no stock moved, so nothing to replay...
        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body(['quantity' => 0]))
            ->assertStatus(422);

        // ...and the attempt, corrected, may run under the same key.
        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body(['quantity' => 5]))
            ->assertOk();

        $this->assertSame(5.0, $this->onHand($cake));
    }

    public function test_a_key_does_not_reach_another_persons_answer(): void
    {
        $cake = $this->item();
        $key = 'attempt-'.str_repeat('d', 20);

        $this->actingAs($this->staff())
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body())
            ->assertOk();

        $other = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Second Baker',
            'email' => 'baker2@example.test',
            'password' => 'baker-password-2',
            'is_admin' => true,
        ]);
        $other->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $other->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        $other->givePermissionTo(['dashboard.overview.view', 'inventory.stock.restock']);

        $this->actingAs($other->fresh())
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson(route('admin.stock.restock', $cake), $this->body())
            ->assertOk()
            ->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(24.0, $this->onHand($cake));
    }

    public function test_a_malformed_key_is_ignored_rather_than_trusted(): void
    {
        $cake = $this->item();
        $this->actingAs($this->staff());

        foreach (range(1, 2) as $i) {
            $this->withHeaders(['Idempotency-Key' => 'short'])
                ->postJson(route('admin.stock.restock', $cake), $this->body())
                ->assertOk();
        }

        $this->assertSame(24.0, $this->onHand($cake));
    }

    public function test_a_redirect_is_replayed_as_the_same_redirect(): void
    {
        // A plain form post (the storefront checkout, a guest's order) answers with a
        // redirect, not JSON. The second press must land on the same page, running nothing.
        $middleware = app(EnsureIdempotent::class);
        $ran = 0;

        $make = fn () => tap(Request::create('/checkout', 'POST', ['_idempotency_key' => 'form-'.str_repeat('e', 24), 'x' => '1']),
            fn (Request $r) => $r->setLaravelSession(app('session.store')));

        $first = $middleware->handle($make(), function () use (&$ran) {
            $ran++;

            return redirect('/orders/17');
        });

        $second = $middleware->handle($make(), function () use (&$ran) {
            $ran++;

            return redirect('/somewhere-else');
        });

        $this->assertSame(1, $ran);
        $this->assertSame($first->getTargetUrl(), $second->getTargetUrl());
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
    }
}
