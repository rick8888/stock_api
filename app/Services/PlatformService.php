<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Repositories\Eloquent\PlatformInvoiceRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformService
{
    private const DEMO_TODAY = '2026-08-26';

    public function __construct(
        private readonly SubscriptionPlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlatformInvoiceRepository $invoices,
    ) {}

    public function companies(): Collection
    {
        return Company::query()->orderBy('name')->get()->map(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'ice' => $company->ice,
            'city' => $company->city,
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'createdAt' => optional($company->created_at)->toDateString() ?? self::DEMO_TODAY,
            'usersCount' => $company->users_count,
            'warehousesCount' => $company->warehouses_count,
        ]);
    }

    public function plans(): Collection
    {
        return $this->plans->all();
    }

    public function subscriptions(): Collection
    {
        return $this->subscriptions->all();
    }

    public function invoices(): Collection
    {
        return $this->invoices->all();
    }

    public function createCompany(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $name = trim($input['name'] ?? '');
            if (strlen($name) < 2) {
                throw new DomainException('Le nom de l’entreprise est requis.');
            }
            $plan = $this->plans->find($input['planId'] ?? '');
            if (! $plan || empty($plan['active'])) {
                throw new DomainException('Plan introuvable.');
            }
            $id = Uid::make('co');
            $company = Company::query()->create([
                'id' => $id,
                'name' => $name,
                'legal_name' => trim($input['legalName'] ?? '') ?: $name,
                'ice' => trim($input['ice'] ?? ''),
                'city' => trim($input['city'] ?? ''),
                'email' => strtolower(trim($input['email'] ?? '')),
                'phone' => trim($input['phone'] ?? ''),
                'status' => 'trial',
                'users_count' => 1,
                'warehouses_count' => 1,
            ]);
            $ends = $this->trialEnd();
            $this->subscriptions->put([
                'id' => Uid::make('sub'),
                'companyId' => $company->id,
                'planId' => $plan['id'],
                'status' => 'trialing',
                'interval' => $input['interval'] ?? 'monthly',
                'startedAt' => self::DEMO_TODAY,
                'currentPeriodEnd' => $ends,
                'trialEndsAt' => $ends,
                'seats' => 1,
            ]);

            return $this->companyArray($company);
        });
    }

    public function updateCompany(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $company = Company::query()->find($id);
            if (! $company) {
                throw new DomainException('Entreprise introuvable.');
            }
            foreach (['name' => 'name', 'legalName' => 'legal_name', 'ice' => 'ice', 'city' => 'city', 'phone' => 'phone'] as $from => $to) {
                if (array_key_exists($from, $input)) {
                    $company->{$to} = trim((string) $input[$from]);
                }
            }
            if (array_key_exists('email', $input)) {
                $company->email = strtolower(trim((string) $input['email']));
            }
            if (! empty($input['status'])) {
                $company->status = $input['status'];
                $subscription = $this->subscriptions->all()->first(fn (array $row) => $row['companyId'] === $id);
                if ($subscription) {
                    if ($input['status'] === 'suspended') {
                        $subscription['status'] = 'paused';
                    }
                    if ($input['status'] === 'churned') {
                        $subscription['status'] = 'canceled';
                    }
                    if ($input['status'] === 'active' && in_array($subscription['status'], ['paused', 'canceled'], true)) {
                        $subscription['status'] = 'active';
                    }
                    if ($input['status'] === 'trial') {
                        $subscription['status'] = 'trialing';
                    }
                    $this->subscriptions->put($subscription);
                }
            }
            $company->save();

            return $this->companyArray($company);
        });
    }

    public function createPlan(array $input): array
    {
        $name = trim($input['name'] ?? '');
        if (strlen($name) < 2) {
            throw new DomainException('Le nom du plan est requis.');
        }
        $code = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['code'] ?? '') ?: $name) ?? '');
        if ($this->plans->all()->contains(fn (array $plan) => $plan['code'] === $code)) {
            throw new DomainException('Ce code de plan existe déjà.');
        }

        return $this->plans->put([
            'id' => Uid::make('plan'),
            'name' => $name,
            'code' => $code,
            'description' => trim($input['description'] ?? ''),
            'monthlyPrice' => max(0, (int) round($input['monthlyPrice'] ?? 0)),
            'yearlyPrice' => max(0, (int) round($input['yearlyPrice'] ?? 0)),
            'maxUsers' => max(0, (int) round($input['maxUsers'] ?? 0)),
            'maxWarehouses' => max(0, (int) round($input['maxWarehouses'] ?? 0)),
            'modules' => $input['modules'] ?? [],
            'active' => (bool) ($input['active'] ?? true),
        ]);
    }

    public function updatePlan(string $id, array $input): array
    {
        $plan = $this->plans->findOrFail($id, 'Plan introuvable.');
        if (array_key_exists('name', $input)) {
            $plan['name'] = trim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $plan['description'] = trim($input['description']);
        }
        foreach (['monthlyPrice', 'yearlyPrice', 'maxUsers', 'maxWarehouses'] as $key) {
            if (array_key_exists($key, $input)) {
                $plan[$key] = max(0, (int) round($input[$key]));
            }
        }
        if (array_key_exists('modules', $input)) {
            $plan['modules'] = $input['modules'];
        }
        if (array_key_exists('active', $input)) {
            $plan['active'] = (bool) $input['active'];
        }

        return $this->plans->put($plan);
    }

    public function changeSubscription(string $companyId, array $input): array
    {
        return DB::transaction(function () use ($companyId, $input) {
            $subscription = $this->subscriptions->all()->first(fn (array $row) => $row['companyId'] === $companyId);
            if (! $subscription) {
                throw new DomainException('Abonnement introuvable.');
            }
            $company = Company::query()->find($companyId);
            if (! empty($input['planId'])) {
                $plan = $this->plans->find($input['planId']);
                if (! $plan) {
                    throw new DomainException('Plan introuvable.');
                }
                $subscription['planId'] = $plan['id'];
            }
            if (! empty($input['interval'])) {
                $subscription['interval'] = $input['interval'];
                $subscription['currentPeriodEnd'] = $this->periodEnd($input['interval']);
            }
            if (! empty($input['status'])) {
                $subscription['status'] = $input['status'];
                if ($company) {
                    if ($input['status'] === 'paused') {
                        $company->status = 'suspended';
                    }
                    if ($input['status'] === 'canceled') {
                        $company->status = 'churned';
                    }
                    if ($input['status'] === 'active') {
                        $company->status = 'active';
                    }
                    if ($input['status'] === 'trialing') {
                        $company->status = 'trial';
                    }
                    $company->save();
                }
            }
            $this->subscriptions->put($subscription);
            if (! empty($input['planId']) || ! empty($input['interval'])) {
                $plan = $this->plans->find($subscription['planId']);
                if ($plan && ($subscription['status'] ?? '') === 'active') {
                    $this->invoices->put([
                        'id' => Uid::make('pinv'),
                        'companyId' => $companyId,
                        'subscriptionId' => $subscription['id'],
                        'number' => $this->nextInvoiceNumber(),
                        'amount' => ($subscription['interval'] ?? '') === 'yearly' ? $plan['yearlyPrice'] : $plan['monthlyPrice'],
                        'status' => 'open',
                        'issuedAt' => self::DEMO_TODAY,
                        'dueDate' => $this->addDays(self::DEMO_TODAY, 14),
                    ]);
                }
            }

            return $subscription;
        });
    }

    public function payInvoice(string $id): void
    {
        DB::transaction(function () use ($id) {
            $invoice = $this->invoices->findOrFail($id, 'Facture introuvable.');
            $invoice['status'] = 'paid';
            $invoice['paidAt'] = self::DEMO_TODAY;
            $this->invoices->put($invoice);
            $overdue = $this->invoices->all()->contains(
                fn (array $item) => $item['companyId'] === $invoice['companyId'] && in_array($item['status'] ?? '', ['overdue', 'open'], true),
            );
            $subscription = $this->subscriptions->all()->first(fn (array $row) => $row['companyId'] === $invoice['companyId']);
            $company = Company::query()->find($invoice['companyId']);
            if (! $overdue && ($subscription['status'] ?? '') === 'past_due') {
                $subscription['status'] = 'active';
                $this->subscriptions->put($subscription);
                if ($company && $company->status !== 'suspended') {
                    $company->status = 'active';
                    $company->save();
                }
            }
        });
    }

    private function companyArray(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'ice' => $company->ice,
            'city' => $company->city,
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'createdAt' => optional($company->created_at)->toDateString() ?? self::DEMO_TODAY,
            'usersCount' => $company->users_count,
            'warehousesCount' => $company->warehouses_count,
        ];
    }

    private function periodEnd(string $interval, string $from = self::DEMO_TODAY): string
    {
        $date = new \DateTimeImmutable($from.'T12:00:00');
        $date = $interval === 'yearly' ? $date->modify('+1 year') : $date->modify('+1 month');

        return $date->format('Y-m-d');
    }

    private function trialEnd(string $from = self::DEMO_TODAY): string
    {
        return $this->addDays($from, 14);
    }

    private function addDays(string $from, int $days): string
    {
        return (new \DateTimeImmutable($from.'T12:00:00'))->modify("+$days days")->format('Y-m-d');
    }

    private function nextInvoiceNumber(): string
    {
        $count = $this->invoices->all()->count() + 1;

        return 'BS-2026-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
    }
}
