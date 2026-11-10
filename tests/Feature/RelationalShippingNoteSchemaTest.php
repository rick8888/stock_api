<?php

namespace Tests\Feature;

use App\Models\ShippingNote;
use App\Models\User;
use App\Repositories\Eloquent\ShippingNoteRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelationalShippingNoteSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-bl',
            'first_name' => 'Bl',
            'last_name' => 'Ops',
            'email' => 'bl@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 8,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-bl',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_shipping_note_persists_header_columns_and_line_rows(): void
    {
        $repo = app(ShippingNoteRepository::class);
        $note = $repo->put([
            'id' => 'bl-rel-1',
            'code' => 'BL-2026-01001',
            'warehouseId' => 'wh-1',
            'client' => 'Pharmacie Test',
            'address' => 'Habous',
            'city' => 'Casablanca',
            'status' => 'ready',
            'carrierId' => 'car-ctm',
            'carrierName' => 'CTM Messagerie',
            'trackingNumber' => 'CTM-01001-ABC',
            'companyId' => 'co-bl',
            'lines' => [
                [
                    'productId' => 'prd-1',
                    'productName' => 'Paracétamol',
                    'sku' => 'PARA',
                    'quantity' => 12,
                    'unit' => 'boîte',
                ],
                [
                    'productId' => 'prd-2',
                    'productName' => 'Sérum',
                    'sku' => 'SER',
                    'quantity' => 3,
                    'unit' => 'flacon',
                ],
            ],
        ]);

        $this->assertDatabaseHas('shipping_notes', [
            'id' => 'bl-rel-1',
            'code' => 'BL-2026-01001',
            'warehouse_id' => 'wh-1',
            'client' => 'Pharmacie Test',
            'carrier_id' => 'car-ctm',
            'tracking_number' => 'CTM-01001-ABC',
            'status' => 'ready',
        ]);
        $this->assertDatabaseCount('shipping_note_lines', 2);
        $this->assertSame(2, count($note['lines']));
        $this->assertSame(12, (int) $note['lines'][0]['quantity']);

        $found = $repo->find('bl-rel-1');
        $this->assertSame('Pharmacie Test', $found['client'] ?? null);
        $this->assertSame('prd-2', $found['lines'][1]['productId'] ?? null);
    }

    public function test_legacy_payload_only_note_still_readable(): void
    {
        DB::table('shipping_notes')->insert([
            'id' => 'bl-legacy',
            'payload' => json_encode([
                'id' => 'bl-legacy',
                'code' => 'BL-LEGACY',
                'warehouseId' => 'wh-1',
                'client' => 'Legacy',
                'address' => 'X',
                'city' => 'Rabat',
                'status' => 'ready',
                'lines' => [
                    ['productId' => 'prd-9', 'productName' => 'Old', 'quantity' => 1, 'unit' => 'u'],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $doc = ShippingNote::query()->find('bl-legacy')?->document();
        $this->assertSame('Legacy', $doc['client'] ?? null);
        $this->assertSame('prd-9', $doc['lines'][0]['productId'] ?? null);
    }
}
