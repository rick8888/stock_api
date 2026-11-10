<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Repositories\Eloquent\SubscriptionRepository;
use Illuminate\Console\Command;

class ExpireTrialsCommand extends Command
{
    protected $signature = 'billing:expire-trials';

    protected $description = 'Passe les essais expirés en canceled et les entreprises en churned';

    public function handle(SubscriptionRepository $subscriptions): int
    {
        $expired = $subscriptions->expiredTrials();
        $count = 0;
        foreach ($expired as $subscription) {
            $subscription['status'] = 'canceled';
            $subscriptions->put($subscription);
            $company = Company::query()->find($subscription['companyId'] ?? '');
            if ($company && $company->status === 'trial') {
                $company->status = 'churned';
                $company->save();
            }
            $count++;
            $this->line('Essai expiré : '.($subscription['companyId'] ?? $subscription['id']));
        }
        $this->info($count.' abonnement(s) d’essai expiré(s).');

        return self::SUCCESS;
    }
}
