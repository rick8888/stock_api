<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\DriverRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserService
{
    private const ROLE_TITLES = [
        'SUPER_ADMIN' => 'Super administrateur',
        'TENANT_OWNER' => 'Propriétaire',
        'ADMIN' => 'Administrateur',
        'MANAGER' => 'Manager',
        'WAREHOUSE_MANAGER' => 'Gestionnaire d’entrepôt',
        'PROCUREMENT' => 'Approvisionnement',
        'FINANCE' => 'Finance',
        'DISPATCHER' => 'Dispatcher',
        'DRIVER' => 'Chauffeur',
        'AUDITOR' => 'Auditeur',
    ];

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly DriverRepository $drivers,
        private readonly UserDirectory $directory,
        private readonly OpsNotifier $notifier,
        private readonly Tenant $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function list(): Collection
    {
        return $this->tenant->filterUsers($this->directory->all());
    }

    public function get(string $id): ?array
    {
        return $this->directory->find($id);
    }

    public function findByEmail(string $email): ?array
    {
        $user = $this->users->findByEmail($email);

        return $user ? $this->directory->toArray($user) : null;
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $creator = ! empty($input['createdByUserId']) ? $this->users->findById($input['createdByUserId']) : null;
            $this->assertCreatableRole($creator, $input['role']);
            $firstName = trim($input['firstName'] ?? '');
            $lastName = trim($input['lastName'] ?? '');
            if ($firstName === '' || $lastName === '') {
                throw new DomainException('Le nom est requis.');
            }
            $email = strtolower(trim($input['email'] ?? $this->emailFromName($firstName, $lastName)));
            if ($this->users->findByEmail($email)) {
                throw new DomainException('Cet email est déjà utilisé');
            }
            $user = new User;
            $user->id = Uid::make('usr');
            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->email = $email;
            $user->password = trim($input['password'] ?? '') ?: 'demo';
            $user->role = $input['role'];
            $user->title = trim($input['title'] ?? '') ?: (self::ROLE_TITLES[$input['role']] ?? '');
            $user->avatar_hue = 10 + ($this->users->all()->count() % 40);
            $user->warehouse_ids = $input['warehouseIds'] ?? [];
            $user->product_ids = $input['productIds'] ?? [];
            $user->region_ids = $input['regionIds'] ?? [];
            $user->company_id = $input['companyId'] ?? $creator?->company_id;
            if ($user->company_id && ! in_array($user->role, ['SUPER_ADMIN', 'TENANT_OWNER'], true)) {
                $this->entitlements->assertCanAddUser($user->company_id);
            }
            $user->save();
            if ($user->company_id) {
                Company::query()->where('id', $user->company_id)->increment('users_count');
            }
            $this->ensureDriver($user, $input['phone'] ?? null);
            $this->notifier->audit('Utilisateur créé', 'user', $user->id, $input['createdByUserId'] ?? '');

            return $this->directory->toArray($user->fresh());
        });
    }

    public function update(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $user = $this->users->findById($id);
            if (! $user) {
                throw new DomainException('Utilisateur introuvable');
            }
            $actor = ! empty($input['createdByUserId']) ? $this->users->findById($input['createdByUserId']) : null;
            $this->assertUpdatableRole($actor, $user, $input['role'] ?? null);
            if (! empty($input['firstName'])) {
                $user->first_name = trim($input['firstName']);
            }
            if (! empty($input['lastName'])) {
                $user->last_name = trim($input['lastName']);
            }
            if (! empty($input['email'])) {
                $email = strtolower(trim($input['email']));
                $other = $this->users->findByEmail($email);
                if ($other && $other->id !== $id) {
                    throw new DomainException('Cet email est déjà utilisé');
                }
                $user->email = $email;
            }
            if (! empty(trim($input['password'] ?? ''))) {
                $user->password = trim($input['password']);
            }
            if (! empty($input['role'])) {
                $user->role = $input['role'];
            }
            if (! empty($input['title'])) {
                $user->title = trim($input['title']);
            }
            if (array_key_exists('warehouseIds', $input)) {
                $user->warehouse_ids = $input['warehouseIds'];
            }
            if (array_key_exists('productIds', $input)) {
                $user->product_ids = $input['productIds'];
            }
            if (array_key_exists('regionIds', $input)) {
                $user->region_ids = $input['regionIds'];
            }
            $user->save();
            $this->ensureDriver($user, $input['phone'] ?? null);
            $this->notifier->audit('Utilisateur modifié', 'user', $user->id, $input['createdByUserId'] ?? '');

            return $this->directory->toArray($user->fresh());
        });
    }

    private function ensureDriver(User $user, ?string $phone): void
    {
        if ($user->role !== 'DRIVER') {
            return;
        }
        if ($user->driver_id) {
            $driver = $this->drivers->find($user->driver_id);
            if ($driver && $phone) {
                $driver['phone'] = trim($phone);
                $this->drivers->put($driver);
            }

            return;
        }
        $driver = $this->drivers->put([
            'id' => Uid::make('drv'),
            'userId' => $user->id,
            'phone' => trim((string) $phone),
            'status' => 'available',
        ]);
        $user->driver_id = $driver['id'];
        $user->save();
    }

    private function emailFromName(string $firstName, string $lastName): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9.]+/', '', $firstName.'.'.$lastName));
        $base = ($slug ?: 'utilisateur').'@bravo.stock';
        if (! $this->users->findByEmail($base)) {
            return $base;
        }

        return ($slug ?: 'utilisateur').'.'.Uid::make('n').'@bravo.stock';
    }

    private function assertCreatableRole(?User $creator, string $role): void
    {
        if ($role === 'SUPER_ADMIN' && $creator?->role !== 'SUPER_ADMIN') {
            throw new DomainException('Seul Bravo.Stock peut créer ce rôle.');
        }
        if ($role === 'TENANT_OWNER' && $creator?->role !== 'SUPER_ADMIN') {
            throw new DomainException('Seul Bravo.Stock peut créer un propriétaire d’entreprise.');
        }
        if ($creator?->role === 'TENANT_OWNER' && $role !== 'ADMIN') {
            throw new DomainException('Le propriétaire ne crée que des administrateurs.');
        }
        if ($creator?->role === 'ADMIN' && in_array($role, ['SUPER_ADMIN', 'TENANT_OWNER'], true)) {
            throw new DomainException('Rôle non autorisé.');
        }
    }

    private function assertUpdatableRole(?User $actor, User $target, ?string $nextRole): void
    {
        if ($actor?->role === 'TENANT_OWNER') {
            if ($target->company_id !== $actor->company_id || $target->role !== 'ADMIN') {
                throw new DomainException('Vous ne pouvez modifier que les administrateurs de votre entreprise.');
            }
            if ($nextRole && $nextRole !== 'ADMIN') {
                throw new DomainException('Le propriétaire ne gère que des administrateurs.');
            }
        }
        if ($nextRole) {
            $this->assertCreatableRole($actor, $nextRole);
        }
    }
}
