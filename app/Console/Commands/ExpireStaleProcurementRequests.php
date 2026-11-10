<?php

namespace App\Console\Commands;

use App\Services\Procurement\ProcurementExpiryService;
use Illuminate\Console\Command;

class ExpireStaleProcurementRequests extends Command
{
    protected $signature = 'procurement:expire-stale
        {--days= : Jours sans activité au-delà desquels une pièce est périmée}
        {--dry-run : Afficher les pièces concernées sans rien modifier}';

    protected $description = 'Périme les consultations et demandes d\'achat laissées sans suite, qui gonflent les propositions de réapprovisionnement';

    public function handle(ProcurementExpiryService $expiry): int
    {
        // Sans ce test explicite, --days=0 serait confondu avec l'option absente.
        $option = $this->option('days');
        $days = $option === null || $option === '' ? ProcurementExpiryService::EXPIRY_DAYS : (int) $option;

        if ($days < 1) {
            $this->error('Le délai doit valoir au moins 1 jour.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $consultations = $expiry->staleConsultations($days);
            $requests = $expiry->stale($days);

            foreach ($consultations as $consultation) {
                $this->line('Consultation : '.$this->describeConsultation($consultation));
            }
            foreach ($requests as $request) {
                $this->line('Demande : '.$this->describeRequest($request, $request['status'] ?? '?'));
            }

            $this->info(sprintf(
                '%d consultation(s) et %d demande(s) seraient périmées (seuil %d j).',
                $consultations->count(),
                $requests->count(),
                $days,
            ));

            return self::SUCCESS;
        }

        $result = $expiry->expire($days);

        foreach ($result['consultations'] as $consultation) {
            $this->line('Consultation périmée : '.$this->describeConsultation($consultation));
        }
        foreach ($result['requests'] as $request) {
            $this->line('Demande périmée : '.$this->describeRequest($request));
        }

        $this->info(sprintf(
            '%d consultation(s) et %d demande(s) périmée(s) (seuil %d j).',
            $result['consultations']->count(),
            $result['requests']->count(),
            $days,
        ));

        return self::SUCCESS;
    }

    private function describeRequest(array $request, ?string $status = null): string
    {
        $activity = ProcurementExpiryService::lastActivityAt($request);
        $age = $activity ? (int) $activity->diffInDays(now()) : 0;
        $quantity = collect($request['items'] ?? [])->sum(fn (array $item) => (int) ($item['quantity'] ?? 0));

        return sprintf(
            '%s · %s%d j sans suite · %d unité(s) · %s',
            $request['code'] ?? $request['id'],
            $status ? $status.' · ' : '',
            $age,
            $quantity,
            $request['warehouseId'] ?? '-',
        );
    }

    private function describeConsultation(array $consultation): string
    {
        $activity = ProcurementExpiryService::consultationActivityAt($consultation);
        $age = $activity ? (int) $activity->diffInDays(now()) : 0;
        $count = count($consultation['procurementRequestIds'] ?? []);

        return sprintf(
            '%s · %s · %d j sans suite · %d demande(s) · %s',
            $consultation['code'] ?? $consultation['id'],
            $consultation['status'] ?? '?',
            $age,
            $count,
            $consultation['warehouseId'] ?? '-',
        );
    }
}
