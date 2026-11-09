<?php

$root = dirname(__DIR__);
$docs = [
    'Permission' => ['permissions', 'prm'],
    'Category' => ['categories', 'cat'],
    'Product' => ['products', 'prd'],
    'Warehouse' => ['warehouses', 'wh'],
    'Supplier' => ['suppliers', 'sup'],
    'StockItem' => ['stock_items', 'stk'],
    'StockMovement' => ['stock_movements', 'mov'],
    'InventorySession' => ['inventory_sessions', 'inv'],
    'ProcurementRequest' => ['procurement_requests', 'pr'],
    'Consultation' => ['consultations', 'cs'],
    'PurchaseOrder' => ['purchase_orders', 'po'],
    'Receipt' => ['receipts', 'rcp'],
    'Driver' => ['drivers', 'drv'],
    'Vehicle' => ['vehicles', 'veh'],
    'ShippingNote' => ['shipping_notes', 'bl'],
    'Route' => ['routes', 'rt'],
    'Shipment' => ['shipments', 'shp'],
    'Delivery' => ['deliveries', 'dlv'],
    'TaskItem' => ['tasks', 'tsk'],
    'Alert' => ['alerts', 'al'],
    'PlanningEvent' => ['planning_events', 'pl'],
    'Invoice' => ['invoices', 'invc'],
    'Payment' => ['payments', 'pay'],
    'Budget' => ['budgets', 'bdg'],
    'Expense' => ['expenses', 'exp'],
    'SubscriptionPlan' => ['subscription_plans', 'plan'],
    'Subscription' => ['subscriptions', 'sub'],
    'PlatformInvoice' => ['platform_invoices', 'pinv'],
    'AppNotification' => ['notifications', 'ntf'],
    'AuditLog' => ['audit_logs', 'aud'],
];

foreach ($docs as $class => [$table, $prefix]) {
    $model = <<<PHP
<?php

namespace App\\Models;

class {$class} extends DocumentModel
{
    protected \$table = '{$table}';
}

PHP;
    file_put_contents("$root/app/Models/{$class}.php", $model);

    $repo = <<<PHP
<?php

namespace App\\Repositories\\Eloquent;

use App\\Models\\{$class};

class {$class}Repository extends DocumentRepository
{
    protected string \$modelClass = {$class}::class;

    protected string \$idPrefix = '{$prefix}';
}

PHP;
    file_put_contents("$root/app/Repositories/Eloquent/{$class}Repository.php", $repo);
}

echo 'Generated '.count($docs)." models and repositories\n";
