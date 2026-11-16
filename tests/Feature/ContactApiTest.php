<?php

namespace Tests\Feature;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Sara Demo',
            'email' => 'sara@exemple.ma',
            'company' => 'Atlas Retail',
            'phone' => '+212600000000',
            'message' => 'Nous voulons une démo stock + tournées.',
            ...$overrides,
        ];
    }

    private function actingAsRole(string $role): void
    {
        $user = User::query()->create([
            'id' => 'usr-contact-'.strtolower($role),
            'first_name' => 'Test',
            'last_name' => $role,
            'email' => strtolower($role).'-contact@test.local',
            'password' => 'demo',
            'role' => $role,
            'title' => $role,
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-contact',
        ]);
        $this->actingAs($user, 'api');
    }

    public function test_public_contact_form_stores_a_message(): void
    {
        $response = $this->postJson('/api/contact', $this->payload());

        $response->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('contact_messages', [
            'email' => 'sara@exemple.ma',
            'company' => 'Atlas Retail',
            'source' => 'landing',
        ]);
        $this->assertSame(1, ContactMessage::query()->count());
    }

    public function test_contact_requires_core_fields(): void
    {
        $this->postJson('/api/contact', [
            'name' => '',
            'email' => 'pas-un-email',
            'message' => '',
        ])->assertStatus(422);
    }

    public function test_contact_notifies_configured_recipients(): void
    {
        Mail::fake();
        config(['services.contact.notify_email' => 'ventes@bravo.test, ops@bravo.test']);

        $this->postJson('/api/contact', $this->payload())->assertCreated();

        Mail::assertSent(ContactMessageReceived::class, function (ContactMessageReceived $mail) {
            return $mail->hasTo('ventes@bravo.test')
                && $mail->hasTo('ops@bravo.test')
                && $mail->hasReplyTo('sara@exemple.ma');
        });
    }

    public function test_contact_sends_nothing_without_recipients(): void
    {
        Mail::fake();
        config(['services.contact.notify_email' => null]);

        $this->postJson('/api/contact', $this->payload())->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_mail_failure_does_not_break_the_form(): void
    {
        config(['services.contact.notify_email' => 'ventes@bravo.test']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->postJson('/api/contact', $this->payload())->assertCreated();
        $this->assertSame(1, ContactMessage::query()->count());
    }

    public function test_super_admin_lists_and_handles_messages(): void
    {
        $this->postJson('/api/contact', $this->payload());
        $this->postJson('/api/contact', $this->payload(['email' => 'autre@exemple.ma']));
        $this->actingAsRole('SUPER_ADMIN');

        $list = $this->getJson('/api/platform/contact-messages')->assertOk();
        $this->assertCount(2, $list->json());
        $id = $list->json('0.id');
        $this->assertNull($list->json('0.handledAt'));

        $this->patchJson("/api/platform/contact-messages/{$id}", ['handled' => true])
            ->assertOk()
            ->assertJsonPath('id', $id);
        $this->assertCount(1, $this->getJson('/api/platform/contact-messages?status=open')->json());
        $this->assertCount(1, $this->getJson('/api/platform/contact-messages?status=handled')->json());

        $this->patchJson("/api/platform/contact-messages/{$id}", ['handled' => false])
            ->assertOk()
            ->assertJsonPath('handledAt', null);
    }

    public function test_tenant_admin_cannot_read_contact_messages(): void
    {
        $this->actingAsRole('ADMIN');

        $this->getJson('/api/platform/contact-messages')->assertForbidden();
    }
}
