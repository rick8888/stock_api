<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_form_stores_a_message(): void
    {
        $response = $this->postJson('/api/contact', [
            'name' => 'Sara Demo',
            'email' => 'sara@exemple.ma',
            'company' => 'Atlas Retail',
            'phone' => '+212600000000',
            'message' => 'Nous voulons une démo stock + tournées.',
        ]);

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
}
