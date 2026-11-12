<?php

namespace Tests\Feature;

use App\Services\SupplierScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_score_blends_declared_reliability_with_on_time_history(): void
    {
        DB::table('purchase_orders')->insert([
            [
                'id' => 'po-ok',
                'supplier_id' => 'sup-x',
                'status' => 'received',
                'expected_date' => now()->subDays(2)->toDateString(),
                'company_id' => 'co-test',
                'payload' => json_encode([
                    'id' => 'po-ok',
                    'supplierId' => 'sup-x',
                    'status' => 'received',
                    'expectedDate' => now()->subDays(2)->toDateString(),
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'po-late',
                'supplier_id' => 'sup-x',
                'status' => 'delayed',
                'expected_date' => now()->subDays(5)->toDateString(),
                'company_id' => 'co-test',
                'payload' => json_encode([
                    'id' => 'po-late',
                    'supplierId' => 'sup-x',
                    'status' => 'delayed',
                    'expectedDate' => now()->subDays(5)->toDateString(),
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $score = app(SupplierScoreService::class)->score([
            'id' => 'sup-x',
            'reliability' => 90,
        ]);

        $this->assertSame(90, $score['declaredReliability']);
        $this->assertSame(50, $score['onTimeRate']);
        $this->assertSame(2, $score['orderSampleSize']);
        // 0.4*90 + 0.6*50 = 66
        $this->assertSame(66, $score['reliability']);
        $this->assertNotEmpty($score['reasons']);
    }

    public function test_score_falls_back_to_declared_without_history(): void
    {
        $score = app(SupplierScoreService::class)->score([
            'id' => 'sup-new',
            'reliability' => 88,
        ]);

        $this->assertSame(88, $score['reliability']);
        $this->assertNull($score['onTimeRate']);
        $this->assertSame(0, $score['orderSampleSize']);
    }
}
