<?php

namespace App\Services\Procurement;

use App\Models\Consultation;
use App\Models\PurchaseOrder;
use App\Repositories\Eloquent\ConsultationRepository;
use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Services\OpsNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Périme les demandes d'achat et les consultations laissées sans suite.
 *
 * Une demande soumise ou validée consomme la position de stock dans le moteur
 * de réapprovisionnement. Une consultation ouverte la protège : tant que les
 * devis traînent sans être retenus, la DA gonfle les quantités proposées.
 * On commence donc par clôturer les consultations abandonnées, puis on périme
 * les DA qui n'ont plus de couverture.
 */
class ProcurementExpiryService
{
    /** Jours sans activité au-delà desquels une pièce est tenue pour abandonnée. */
    public const EXPIRY_DAYS = 45;

    /** Statuts de DA encore en attente d'une suite. */
    public const EXPIRABLE_STATUSES = ['submitted', 'in_review', 'approved'];

    public const STATUS_EXPIRED = 'expired';

    /** Consultations encore susceptibles d'aboutir à une commande. */
    public const OPEN_CONSULTATION_STATUSES = ['sent', 'quoted'];

    public const CONSULTATION_STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly ProcurementRequestRepository $requests,
        private readonly ConsultationRepository $consultations,
        private readonly OpsNotifier $notifier,
    ) {}

    /**
     * Consultations ouvertes sans suite, sans rien modifier.
     *
     * @return Collection<int, array>
     */
    public function staleConsultations(?int $days = null, ?CarbonImmutable $now = null): Collection
    {
        $days = $days ?? self::EXPIRY_DAYS;
        $now = $now ?? CarbonImmutable::now();
        $deadline = $now->subDays($days);

        return $this->consultations->all()
            ->filter(function (array $consultation) use ($deadline) {
                if (! in_array($consultation['status'] ?? '', self::OPEN_CONSULTATION_STATUSES, true)) {
                    return false;
                }
                $activity = self::consultationActivityAt($consultation);

                return $activity !== null && $activity->lessThanOrEqualTo($deadline);
            })
            ->values();
    }

    /**
     * Demandes périmées, sans rien modifier.
     *
     * @return Collection<int, array>
     */
    public function stale(?int $days = null, ?CarbonImmutable $now = null): Collection
    {
        $days = $days ?? self::EXPIRY_DAYS;
        $now = $now ?? CarbonImmutable::now();
        $deadline = $now->subDays($days);
        $engaged = $this->engagedRequestIds();

        return $this->requests->all()
            ->filter(function (array $request) use ($deadline, $engaged) {
                if (! in_array($request['status'] ?? '', self::EXPIRABLE_STATUSES, true)) {
                    return false;
                }
                // Une demande déjà consultée ou commandée est en cours de traitement.
                if (isset($engaged[$request['id'] ?? ''])) {
                    return false;
                }
                $activity = self::lastActivityAt($request);

                return $activity !== null && $activity->lessThanOrEqualTo($deadline);
            })
            ->values();
    }

    /**
     * Clôture les consultations abandonnées, puis périme les DA sans couverture.
     *
     * @return array{consultations: Collection<int, array>, requests: Collection<int, array>}
     */
    public function expire(?int $days = null, ?CarbonImmutable $now = null): array
    {
        $days = $days ?? self::EXPIRY_DAYS;
        $now = $now ?? CarbonImmutable::now();

        $consultations = $this->expireConsultations($days, $now);
        $requests = $this->expireRequests($days, $now);

        return [
            'consultations' => $consultations,
            'requests' => $requests,
        ];
    }

    /**
     * Dernière trace d'activité d'une DA : la décision prime sur la soumission,
     * qui prime sur la création.
     */
    public static function lastActivityAt(array $request): ?CarbonImmutable
    {
        foreach (['decidedAt', 'submittedAt', 'createdAt'] as $field) {
            if (! empty($request[$field])) {
                return CarbonImmutable::parse((string) $request[$field]);
            }
        }

        return null;
    }

    /**
     * Dernière trace d'activité d'une consultation : devis reçus, envoi, création.
     */
    public static function consultationActivityAt(array $consultation): ?CarbonImmutable
    {
        foreach (['quotedAt', 'sentAt', 'createdAt'] as $field) {
            if (! empty($consultation[$field])) {
                return CarbonImmutable::parse((string) $consultation[$field]);
            }
        }

        return null;
    }

    /**
     * @return Collection<int, array>
     */
    private function expireConsultations(int $days, CarbonImmutable $now): Collection
    {
        return $this->staleConsultations($days, $now)->map(function (array $consultation) use ($days) {
            $cancelled = DB::transaction(fn () => $this->consultations->merge($consultation['id'], [
                'status' => self::CONSULTATION_STATUS_CANCELLED,
                'note' => trim(($consultation['note'] ?? '')."\nPérimée automatiquement : aucune suite depuis {$days} jours."),
            ], 'Consultation introuvable'));

            $this->announceConsultation($cancelled, $days);

            return $cancelled;
        })->values();
    }

    /**
     * @return Collection<int, array>
     */
    private function expireRequests(int $days, CarbonImmutable $now): Collection
    {
        return $this->stale($days, $now)->map(function (array $request) use ($days) {
            $expired = DB::transaction(fn () => $this->requests->merge($request['id'], [
                'status' => self::STATUS_EXPIRED,
                'decisionNote' => "Périmée automatiquement : aucune suite depuis {$days} jours.",
            ], 'Demande introuvable'));

            $this->announceRequest($expired, $days);

            return $expired;
        })->values();
    }

    private function announceConsultation(array $consultation, int $days): void
    {
        $code = $consultation['code'] ?? strtoupper((string) $consultation['id']);
        $companyId = $consultation['companyId'] ?? null;

        $this->notifier->alert([
            'title' => $code.' périmée',
            'message' => "Sans suite depuis {$days} jours, cette consultation a été annulée. Les devis reçus ne sont plus retenables — relancer si le besoin tient.",
            'severity' => 'warning',
            'domain' => 'procurement',
            'relatedId' => $consultation['id'],
            'warehouseId' => $consultation['warehouseId'] ?? null,
            'roles' => ['PROCUREMENT', 'WAREHOUSE_MANAGER'],
            'companyId' => $companyId,
        ]);

        $this->notifier->audit('consultation.expired', 'consultation', (string) $consultation['id']);
    }

    private function announceRequest(array $request, int $days): void
    {
        $code = $request['code'] ?? strtoupper((string) $request['id']);
        $companyId = $request['companyId'] ?? null;

        $this->notifier->alert([
            'title' => $code.' périmée',
            'message' => "Sans suite depuis {$days} jours, cette demande a été fermée automatiquement. À resoumettre si le besoin tient toujours.",
            'severity' => 'warning',
            'domain' => 'procurement',
            'relatedId' => $request['id'],
            'warehouseId' => $request['warehouseId'] ?? null,
            'userId' => $request['requesterId'] ?? null,
            'roles' => ['WAREHOUSE_MANAGER'],
            // La tâche planifiée n'a pas d'utilisateur authentifié : sans cette
            // reprise explicite, l'alerte n'appartiendrait à aucune entreprise.
            'companyId' => $companyId,
        ]);

        $this->notifier->audit('procurement.expired', 'procurement_request', (string) $request['id']);
    }

    /**
     * Identifiants des demandes rattachées à une consultation ouverte ou à une
     * commande, et donc déjà engagées dans un processus d'achat.
     *
     * @return array<string, true>
     */
    private function engagedRequestIds(): array
    {
        $ids = [];

        $sources = [
            Consultation::query()
                ->whereIn('status', self::OPEN_CONSULTATION_STATUSES)
                ->pluck('procurement_request_ids'),
            PurchaseOrder::query()->pluck('procurement_request_ids'),
        ];

        foreach ($sources as $rows) {
            foreach ($rows as $value) {
                foreach ((is_array($value) ? $value : json_decode((string) $value, true) ?? []) as $id) {
                    $ids[(string) $id] = true;
                }
            }
        }

        foreach (PurchaseOrder::query()->whereNotNull('procurement_request_id')->pluck('procurement_request_id') as $id) {
            $ids[(string) $id] = true;
        }

        return $ids;
    }
}
