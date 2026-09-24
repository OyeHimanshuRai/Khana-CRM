<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries the /er sub-path, which would prefix every test
        // request and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'viewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo('content.faqs.view');

        return $user;
    }

    private function faq(array $attributes = []): Faq
    {
        return Faq::create([
            'question' => 'Do you ship internationally?',
            'answer' => 'Yes, to most countries.',
            'category' => 'Shipping',
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->faq();

        $this->actingAs($this->admin())
            ->get('/admin/faqs')
            ->assertOk()
            ->assertSee('Do you ship internationally?')
            ->assertSee('Add FAQ');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $faq = $this->faq();

        foreach ([
            '/admin/faqs',
            '/admin/faqs/create',
            "/admin/faqs/{$faq->id}",
            "/admin/faqs/{$faq->id}/edit",
        ] as $url) {
            $this->actingAs($this->admin())
                ->withHeader('X-Fragment', '1')
                ->get($url)
                ->assertOk()
                ->assertDontSee('<body', false)
                // Injected markup never runs its scripts, so there are none.
                ->assertDontSee('<script', false);
        }
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $this->faq(['question' => 'How long does delivery take?', 'category' => 'Shipping']);
        $this->faq(['question' => 'Which cards do you accept?', 'category' => 'Billing']);
        $this->faq(['question' => 'Is my warranty transferable?', 'category' => null]);

        $this->actingAs($this->admin())
            ->get('/admin/faqs?q=delivery')
            ->assertOk()
            ->assertSee('How long does delivery take?')
            ->assertDontSee('Which cards do you accept?');

        $this->actingAs($this->admin())
            ->get('/admin/faqs?category=Billing')
            ->assertOk()
            ->assertSee('Which cards do you accept?')
            ->assertDontSee('How long does delivery take?');
    }

    public function test_uncategorised_rows_can_be_filtered_for(): void
    {
        $this->faq(['question' => 'Filed away', 'category' => 'Billing']);
        $this->faq(['question' => 'Loose end', 'category' => null]);

        // "—" is the filter's stand-in for "no category".
        $this->actingAs($this->admin())
            ->get('/admin/faqs?category=%E2%80%94')
            ->assertOk()
            ->assertSee('Loose end')
            ->assertDontSee('Filed away');
    }

    /* -------------------------------------------------------------- write */

    public function test_an_faq_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/faqs', [
                'question' => 'Do you offer gift wrapping?',
                'answer' => 'Yes, free of charge.',
                'category' => 'Orders',
                'is_active' => 1,
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.category', 'Orders');

        $this->assertDatabaseHas('faqs', ['question' => 'Do you offer gift wrapping?']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'faq.created']);
    }

    public function test_the_question_and_answer_are_required(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/faqs', ['question' => '', 'answer' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['question', 'answer']]);
    }

    public function test_an_faq_is_updated(): void
    {
        $faq = $this->faq();

        $this->actingAs($this->admin())
            ->putJson("/admin/faqs/{$faq->id}", [
                'question' => 'Do you ship worldwide?',
                'answer' => 'Almost everywhere.',
                'category' => 'Shipping',
                'is_active' => 1,
            ])
            ->assertOk();

        $this->assertSame('Do you ship worldwide?', $faq->fresh()->question);
    }

    public function test_an_faq_is_deleted(): void
    {
        $faq = $this->faq();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/faqs/{$faq->id}")
            ->assertOk();

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'faq.deleted']);
    }

    public function test_the_status_toggles(): void
    {
        $faq = $this->faq(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/faqs/{$faq->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($faq->fresh()->is_active);
    }

    public function test_an_unticked_active_box_deactivates(): void
    {
        $faq = $this->faq(['is_active' => true]);

        // The form posts is_active=0 from its hidden field.
        $this->actingAs($this->admin())
            ->putJson("/admin/faqs/{$faq->id}", [
                'question' => $faq->question,
                'answer' => $faq->answer,
                'is_active' => 0,
            ])
            ->assertOk();

        $this->assertFalse($faq->fresh()->is_active);
    }

    /* --------------------------------------------------------- categories */

    public function test_categories_are_derived_from_the_rows_in_use(): void
    {
        $this->faq(['question' => 'A', 'category' => 'Shipping']);
        $this->faq(['question' => 'B', 'category' => 'Billing']);
        $this->faq(['question' => 'C', 'category' => 'Shipping']);
        $this->faq(['question' => 'D', 'category' => null]);

        $categories = Faq::categories();

        // Distinct, sorted, and blanks left out.
        $this->assertSame(['Billing', 'Shipping'], $categories->all());
    }

    public function test_a_category_disappears_when_its_last_faq_goes(): void
    {
        $faq = $this->faq(['category' => 'Temporary']);

        $this->assertTrue(Faq::categories()->contains('Temporary'));

        $this->actingAs($this->admin())->deleteJson("/admin/faqs/{$faq->id}")->assertOk();

        // The list cleans itself up - the point of not keeping a table.
        $this->assertFalse(Faq::categories()->contains('Temporary'));
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $faq = $this->faq();

        $this->actingAs($viewer)->get('/admin/faqs')->assertOk();
        $this->actingAs($viewer)->get("/admin/faqs/{$faq->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/faqs/create')->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/admin/faqs', ['question' => 'Q', 'answer' => 'A'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/faqs/{$faq->id}", ['question' => 'Q', 'answer' => 'A'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/faqs/{$faq->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/faqs/{$faq->id}")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/faqs')->assertRedirect('http://localhost/admin/login');
    }
}
