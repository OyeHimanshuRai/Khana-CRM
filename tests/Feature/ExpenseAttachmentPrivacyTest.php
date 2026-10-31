<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A bill photographed for an expense is the business's private paperwork.
 *
 * It used to go to the public disk, where anybody with the link - forwarded,
 * pasted into a chat, left in a browser history - could open it, signed in
 * or not, forever. It now lives on the private disk and opens only through
 * the admin app, for somebody who may see that expense.
 */
class ExpenseAttachmentPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        Storage::fake('public');
        Storage::fake('local');

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    /** @param  array<int, string>  $permissions */
    private function staff(array $permissions, ?int $tenantId = null, ?int $shopId = null): User
    {
        $shopId ??= $this->shop->id;
        $user = User::create([
            'tenant_id' => $tenantId ?? $this->shop->tenant_id, 'name' => 'Staff', 'email' => 'staff'.uniqid().'@example.test',
            'password' => 'Staff-pass-123', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $shopId,
        ]);
        $user->forceFill(['all_shops_view' => false])->save();
        $user->shops()->attach($shopId, ['is_default' => true]);
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function as(User $user): static
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        Auth::forgetGuards();

        return $this->actingAs($user);
    }

    private function recordWithBill(): Expense
    {
        $this->as($this->staff(['finance.expenses.view', 'finance.expenses.create']))
            ->post('/superadmin/expenses', [
                'spent_on' => now()->toDateString(), 'title' => 'Gas cylinder', 'amount' => 1100, 'method' => 'cash',
                'attachment' => UploadedFile::fake()->create('bill.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertOk();

        return Expense::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_a_bill_is_stored_privately(): void
    {
        $expense = $this->recordWithBill();

        $this->assertNotNull($expense->attachment_path);
        Storage::disk('local')->assertExists($expense->attachment_path);
        Storage::disk('public')->assertMissing($expense->attachment_path);
        $this->assertSame(route('admin.expenses.attachment', $expense), $expense->attachmentUrl());
    }

    public function test_the_bill_opens_for_someone_who_may_see_the_expense(): void
    {
        $expense = $this->recordWithBill();

        $this->as($this->staff(['finance.expenses.view']))
            ->get(route('admin.expenses.attachment', $expense))
            ->assertOk();
    }

    public function test_the_bill_does_not_open_for_anyone_else(): void
    {
        $expense = $this->recordWithBill();
        $url = route('admin.expenses.attachment', $expense);

        // Without the right.
        $this->as($this->staff([]))->get($url)->assertForbidden();

        // Signed out.
        Auth::guard('web')->logout();
        $this->flushSession();
        Auth::forgetGuards();
        $this->get($url)->assertRedirect();
        $this->assertGuest();

        // Another company.
        $this->post('/register', [
            'business_name' => 'Rival Cafe', 'name' => 'Rival', 'email' => 'rival@example.test',
            'mobile' => '9999999999', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ]);
        $rival = User::query()->withoutGlobalScopes()->where('email', 'rival@example.test')->firstOrFail();
        $rival->forceFill(['email_verified_at' => now()])->saveQuietly();
        $this->as($rival)->get($url)->assertNotFound();
    }

    public function test_a_bill_already_on_the_public_disk_is_moved_to_the_private_one(): void
    {
        Storage::disk('public')->put('expenses/old-bill.pdf', 'PDF');
        $expense = $this->recordWithBill();
        $expense->forceFill(['attachment_path' => 'expenses/old-bill.pdf'])->saveQuietly();

        $migration = require database_path('migrations/2026_10_30_000200_move_expense_attachments_to_private_disk.php');
        $migration->up();

        Storage::disk('public')->assertMissing('expenses/old-bill.pdf');
        Storage::disk('local')->assertExists('expenses/old-bill.pdf');
        $this->assertSame('PDF', Storage::disk('local')->get('expenses/old-bill.pdf'));
    }

    public function test_deleting_the_expense_deletes_the_private_bill(): void
    {
        $expense = $this->recordWithBill();
        $path = $expense->attachment_path;

        $this->as($this->staff(['finance.expenses.view', 'finance.expenses.delete']))
            ->deleteJson(route('admin.expenses.destroy', $expense))->assertOk();

        Storage::disk('local')->assertMissing($path);
    }
}
