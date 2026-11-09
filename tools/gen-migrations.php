<?php

$root = dirname(__DIR__).'/database/migrations';

$doc = static function (string $stamp, string $table): string {
    $classHint = str_replace(' ', '', ucwords(str_replace('_', ' ', $table)));

    return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->string('id')->primary();
            \$table->json('payload');
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};

PHP;
};

$tables = [
    'permissions',
    'categories',
    'products',
    'warehouses',
    'suppliers',
    'stock_items',
    'stock_movements',
    'inventory_sessions',
    'procurement_requests',
    'consultations',
    'purchase_orders',
    'receipts',
    'drivers',
    'vehicles',
    'shipping_notes',
    'routes',
    'shipments',
    'deliveries',
    'tasks',
    'alerts',
    'planning_events',
    'invoices',
    'payments',
    'budgets',
    'expenses',
    'subscription_plans',
    'subscriptions',
    'platform_invoices',
    'notifications',
    'audit_logs',
];

$i = 1;
foreach ($tables as $table) {
    $stamp = sprintf('2026_08_31_%06d', 100000 + $i);
    $path = "$root/{$stamp}_create_{$table}_table.php";
    file_put_contents($path, $doc($stamp, $table));
    $i++;
}

echo 'Wrote '.count($tables)." table migrations\n";
