<?php

namespace Tests\Feature;

use App\Repositories\Eloquent\ConsultationRepository;
use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Services\Procurement\ProcurementExpiryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementExpiryTest extends TestCase
{
    use RefreshDatabase;

    private ProcurementExpiryService $expiry;

    private ProcurementRequestRepository $requests;

    private ConsultationRepository $consultations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->expiry = app(ProcurementExpiryService::class);
        $this->requests = app(ProcurementRequestRepository::class);
        $this->consultations = app(ConsultationRepository::class);
    }

    public function test_a_request_left_without_follow_up_is_expired(): void
    {
        $this->request('pr-old', 'submitted', submittedDaysAgo: 60);

        $expired = $this->expiry->expire()['requests'];

        $this->assertCount(1, $expired);
        $this->assertSame('expired', $expired->first()['status']);
        $this->assertSame('expired', $this->requests->find('pr-old')['status']);
    }

    public function test_the_expiry_reason_is_recorded_on_the_request(): void
    {
        $this->request('pr-old', 'submitted', submittedDaysAgo: 60);

        $this->expiry->expire();

        $this->assertStringContainsString(
            '45 jours',
            $this->requests->find('pr-old')['decisionNote'] ?? '',
        );
    }

    public function test_a_recent_request_is_left_alone(): void
    {
        $this->request('pr-fresh', 'submitted', submittedDaysAgo: 10);

        $this->assertCount(0, $this->expiry->expire()['requests']);
        $this->assertSame('submitted', $this->requests->find('pr-fresh')['status']);
    }

    public function test_a_request_exactly_on_the_threshold_is_expired(): void
    {
        $this->request('pr-edge', 'submitted', submittedDaysAgo: 45);

        $this->assertCount(1, $this->expiry->stale());
    }

    public function test_a_draft_never_expires(): void
    {
        $this->request('pr-draft', 'draft', submittedDaysAgo: null, createdDaysAgo: 120);

        $this->assertCount(0, $this->expiry->stale());
    }

    public function test_a_closed_request_never_expires(): void
    {
        $this->request('pr-closed', 'closed', submittedDaysAgo: 120);
        $this->request('pr-ordered', 'ordered', submittedDaysAgo: 120);
        $this->request('pr-rejected', 'rejected', submittedDaysAgo: 120);

        $this->assertCount(0, $this->expiry->stale());
    }

    public function test_an_approved_request_is_aged_from_its_decision_not_its_submission(): void
    {
        // Soumise il y a 100 jours mais validée il y a 10 : toujours vivante.
        $this->request('pr-decided', 'approved', submittedDaysAgo: 100, decidedDaysAgo: 10);

        $this->assertCount(0, $this->expiry->stale());
    }

    public function test_a_request_under_an_open_consultation_is_protected_until_the_consultation_expires(): void
    {
        $this->request('pr-consulted', 'approved', submittedDaysAgo: 90, decidedDaysAgo: 90);
        $this->consultation('cs-1', 'quoted', ['pr-consulted'], activityDaysAgo: 10);

        // Consultation récente : la DA reste protégée.
        $this->assertCount(0, $this->expiry->staleConsultations());
        $this->assertCount(0, $this->expiry->stale());
    }

    public function test_a_stale_consultation_is_cancelled_and_releases_its_request(): void
    {
        $this->request('pr-consulted', 'approved', submittedDaysAgo: 90, decidedDaysAgo: 90);
        $this->consultation('cs-old', 'quoted', ['pr-consulted'], activityDaysAgo: 60);

        $result = $this->expiry->expire();

        $this->assertCount(1, $result['consultations']);
        $this->assertSame('cancelled', $this->consultations->find('cs-old')['status']);
        $this->assertCount(1, $result['requests']);
        $this->assertSame('expired', $this->requests->find('pr-consulted')['status']);
    }

    public function test_a_recent_consultation_is_left_alone(): void
    {
        $this->request('pr-fresh-cs', 'approved', submittedDaysAgo: 20, decidedDaysAgo: 20);
        $this->consultation('cs-fresh', 'sent', ['pr-fresh-cs'], activityDaysAgo: 5);

        $result = $this->expiry->expire();

        $this->assertCount(0, $result['consultations']);
        $this->assertSame('sent', $this->consultations->find('cs-fresh')['status']);
        $this->assertCount(0, $result['requests']);
    }

    public function test_an_awarded_consultation_never_expires(): void
    {
        $this->request('pr-awarded', 'ordered', submittedDaysAgo: 90, decidedDaysAgo: 90);
        $this->consultation('cs-awarded', 'awarded', ['pr-awarded'], activityDaysAgo: 90);

        $this->assertCount(0, $this->expiry->staleConsultations());
    }

    public function test_a_request_whose_consultation_was_dropped_expires_again(): void
    {
        $this->request('pr-dropped', 'approved', submittedDaysAgo: 90, decidedDaysAgo: 90);
        $this->consultation('cs-2', 'cancelled', ['pr-dropped'], activityDaysAgo: 90);

        $this->assertCount(1, $this->expiry->stale());
    }

    public function test_a_request_already_turned_into_an_order_is_protected(): void
    {
        $this->request('pr-ordered-2', 'approved', submittedDaysAgo: 90, decidedDaysAgo: 90);
        $this->order('po-1', ['pr-ordered-2']);

        $this->assertCount(0, $this->expiry->stale());
    }

    public function test_the_requester_is_alerted(): void
    {
        $this->request('pr-old', 'submitted', submittedDaysAgo: 60);

        $this->expiry->expire();

        $alert = DB::table('alerts')->first();
        $this->assertNotNull($alert);
        $payload = json_decode((string) $alert->payload, true);
        $this->assertSame('usr-requester', $payload['userId']);
        $this->assertSame('co-test', $payload['companyId']);
        $this->assertSame('warning', $payload['severity']);
        $this->assertStringContainsString('périmée', $payload['title']);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $this->request('pr-old', 'submitted', submittedDaysAgo: 60);
        $this->consultation('cs-old', 'quoted', ['pr-old'], activityDaysAgo: 60);

        $this->artisan('procurement:expire-stale', ['--dry-run' => true])
            ->expectsOutputToContain('1 consultation(s) et 0 demande(s) seraient périmées')
            ->assertSuccessful();

        // En dry-run la consultation protège encore la DA : 0 demande listée.
        $this->assertSame('submitted', $this->requests->find('pr-old')['status']);
        $this->assertSame('quoted', $this->consultations->find('cs-old')['status']);
        $this->assertSame(0, DB::table('alerts')->count());
    }

    public function test_the_command_applies_the_custom_threshold(): void
    {
        $this->request('pr-20', 'submitted', submittedDaysAgo: 20);

        $this->artisan('procurement:expire-stale', ['--days' => 15])->assertSuccessful();

        $this->assertSame('expired', $this->requests->find('pr-20')['status']);
    }

    public function test_the_command_refuses_a_nonsensical_threshold(): void
    {
        $this->artisan('procurement:expire-stale', ['--days' => 0])->assertFailed();
    }

    public function test_the_request_items_survive_the_expiry(): void
    {
        $this->request('pr-old', 'submitted', submittedDaysAgo: 60);

        $this->expiry->expire();

        $this->assertSame(
            [['productId' => 'prd-1', 'quantity' => 120]],
            $this->requests->find('pr-old')['items'],
        );
    }

    private function request(
        string $id,
        string $status,
        ?int $submittedDaysAgo = null,
        ?int $decidedDaysAgo = null,
        ?int $createdDaysAgo = null,
    ): void {
        $this->requests->put([
            'id' => $id,
            'code' => strtoupper($id),
            'status' => $status,
            'requesterId' => 'usr-requester',
            'warehouseId' => 'wh-a',
            'companyId' => 'co-test',
            'priority' => 'medium',
            'items' => [['productId' => 'prd-1', 'quantity' => 120]],
            'createdAt' => now()->subDays($createdDaysAgo ?? $submittedDaysAgo ?? 1)->toIso8601String(),
            'submittedAt' => $submittedDaysAgo === null ? null : now()->subDays($submittedDaysAgo)->toIso8601String(),
            'decidedAt' => $decidedDaysAgo === null ? null : now()->subDays($decidedDaysAgo)->toIso8601String(),
        ]);
    }

    /** @param list<string> $requestIds */
    private function consultation(string $id, string $status, array $requestIds, int $activityDaysAgo = 2): void
    {
        $at = now()->subDays($activityDaysAgo)->toIso8601String();
        $this->consultations->put([
            'id' => $id,
            'code' => strtoupper($id),
            'status' => $status,
            'warehouseId' => 'wh-a',
            'companyId' => 'co-test',
            'procurementRequestIds' => $requestIds,
            'supplierIds' => ['sup-1'],
            'createdAt' => $at,
            'sentAt' => $at,
            'quotedAt' => $status === 'quoted' || $status === 'awarded' ? $at : null,
            'awardedAt' => $status === 'awarded' ? $at : null,
        ]);
    }

    /** @param list<string> $requestIds */
    private function order(string $id, array $requestIds): void
    {
        app(PurchaseOrderRepository::class)->put([
            'id' => $id,
            'code' => strtoupper($id),
            'supplierId' => 'sup-1',
            'warehouseId' => 'wh-a',
            'companyId' => 'co-test',
            'status' => 'sent',
            'items' => [['productId' => 'prd-1', 'quantity' => 120]],
            'expectedDate' => now()->addDays(5)->toDateString(),
            'createdAt' => now()->subDays(2)->toIso8601String(),
            'amount' => 1000,
            'procurementRequestIds' => $requestIds,
        ]);
    }
}
