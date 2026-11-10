<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\EntitlementController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\LogisticsController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\ReplenishmentController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\StockLotController;
use App\Http\Controllers\Api\StockPlacementController;
use App\Http\Controllers\Api\WarehouseLocationController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/webhooks/stripe', [BillingController::class, 'webhook']);

Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/platform/entitlements', [EntitlementController::class, 'show'])->middleware('permission:account.view,platform.view,user.view,stock.view');

    Route::get('/categories', [ProductController::class, 'categories'])->middleware('permission:product.view');
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:product.view');
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:product.create');
    Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:product.view');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->middleware('permission:product.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('permission:product.archive');

    Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouse.view');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouse.create');
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouse.view');
    Route::patch('/warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouse.update');

    Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:product.view');
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:product.create');
    Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->middleware('permission:product.view');
    Route::patch('/suppliers/{id}', [SupplierController::class, 'update'])->middleware('permission:product.update');

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:delivery.view,product.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:delivery.create,delivery.prepare,product.create');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->middleware('permission:delivery.view,product.view');
    Route::patch('/customers/{id}', [CustomerController::class, 'update'])->middleware('permission:delivery.update,delivery.prepare,product.update');

    Route::get('/warehouses/{warehouseId}/locations', [WarehouseLocationController::class, 'index'])->middleware('permission:warehouse.view,stock.view');
    Route::get('/locations', [WarehouseLocationController::class, 'index'])->middleware('permission:warehouse.view,stock.view');
    Route::post('/locations', [WarehouseLocationController::class, 'store'])->middleware('permission:warehouse.update,warehouse.create');
    Route::get('/locations/{id}', [WarehouseLocationController::class, 'show'])->middleware('permission:warehouse.view,stock.view');
    Route::patch('/locations/{id}', [WarehouseLocationController::class, 'update'])->middleware('permission:warehouse.update');

    Route::middleware('module:stock')->group(function () {
        Route::get('/stock', [StockController::class, 'index'])->middleware('permission:stock.view');
        Route::get('/stock/lots', [StockLotController::class, 'index'])->middleware('permission:stock.view');
        Route::get('/stock/placements', [StockPlacementController::class, 'index'])->middleware('permission:stock.view');
        Route::post('/stock/pick-path', [StockPlacementController::class, 'pickPath'])->middleware('permission:stock.view,stock.issue');
        Route::get('/stock/suggest-putaway', [StockPlacementController::class, 'suggestPutaway'])->middleware('permission:stock.view,stock.receive');
        Route::get('/stock/movements', [StockController::class, 'movements'])->middleware('permission:stock.view');
        Route::get('/stock/inventories', [StockController::class, 'inventories'])->middleware('permission:stock.view');
        Route::post('/stock/inventories', [StockController::class, 'startInventory'])->middleware('permission:stock.inventory');
        Route::patch('/stock/inventories/{id}', [StockController::class, 'saveInventory'])->middleware('permission:stock.inventory');
        Route::post('/stock/inventories/{id}/submit', [StockController::class, 'submitInventory'])->middleware('permission:stock.inventory');
        Route::post('/stock/inventories/{id}/complete', [StockController::class, 'completeInventory'])->middleware('permission:stock.inventory');
        Route::post('/stock/receive', [StockController::class, 'receive'])->middleware('permission:stock.receive');
        Route::post('/stock/issue', [StockController::class, 'issue'])->middleware('permission:stock.issue');
        Route::post('/stock/transfer', [StockController::class, 'transfer'])->middleware('permission:stock.transfer');
        Route::post('/stock/adjust', [StockController::class, 'adjust'])->middleware('permission:stock.adjust');
        Route::patch('/stock/assignment', [StockController::class, 'assignment'])->middleware('permission:warehouse.assign');
        Route::post('/stock/close-day', [StockController::class, 'closeDay'])->middleware('permission:stock.inventory');
        Route::post('/stock/return', [StockController::class, 'returnStock'])->middleware('permission:stock.receive');
        Route::get('/receipts', [ReceiptController::class, 'index'])->middleware('permission:stock.view');
        Route::post('/receipts', [ReceiptController::class, 'store'])->middleware('permission:stock.receive');
        Route::post('/receipts/{id}/validate', [ReceiptController::class, 'validateReceipt'])->middleware('permission:stock.receive');
    });

    Route::middleware('module:procurement')->group(function () {
        Route::get('/procurement', [ProcurementController::class, 'index'])->middleware('permission:procurement.view');
        Route::post('/procurement', [ProcurementController::class, 'store'])->middleware('permission:procurement.create');
        Route::patch('/procurement/{id}', [ProcurementController::class, 'update'])->middleware('permission:procurement.approve,procurement.create');
        Route::get('/consultations', [ConsultationController::class, 'index'])->middleware('permission:procurement.view');
        Route::post('/consultations', [ConsultationController::class, 'store'])->middleware('permission:procurement.create');
        Route::patch('/consultations/{id}', [ConsultationController::class, 'update'])->middleware('permission:procurement.create');
    });

    Route::middleware('module:stock,procurement')->group(function () {
        Route::get('/replenishment/suggestions', [ReplenishmentController::class, 'index'])->middleware('permission:procurement.view,stock.view');
        Route::post('/replenishment/simulate', [ReplenishmentController::class, 'simulate'])->middleware('permission:procurement.view,stock.view');
        Route::post('/replenishment/compute', [ReplenishmentController::class, 'compute'])->middleware('permission:procurement.create,purchase.create,stock.inventory');
    });

    Route::middleware('module:purchasing')->group(function () {
        Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchase.view');
        Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchase.create');
        Route::patch('/purchases/{id}', [PurchaseController::class, 'update'])->middleware('permission:purchase.update,purchase.create');
    });

    Route::middleware('module:logistics')->group(function () {
        Route::get('/deliveries', [DeliveryController::class, 'index'])->middleware('permission:delivery.view');
        Route::post('/deliveries', [DeliveryController::class, 'store'])->middleware('permission:delivery.create');
        Route::patch('/deliveries/{id}', [DeliveryController::class, 'update'])->middleware('permission:delivery.update');
        Route::patch('/deliveries/{id}/status', [DeliveryController::class, 'updateStatus'])->middleware('permission:delivery.confirm,delivery.update');
        Route::get('/shipments', [LogisticsController::class, 'shipments'])->middleware('permission:delivery.view');
        Route::post('/shipments', [LogisticsController::class, 'storeShipment'])->middleware('permission:delivery.create');
        Route::patch('/shipments/{id}', [LogisticsController::class, 'updateShipment'])->middleware('permission:delivery.update');
        Route::get('/routes', [LogisticsController::class, 'routes'])->middleware('permission:delivery.view');
        Route::post('/routes', [LogisticsController::class, 'storeRoute'])->middleware('permission:delivery.create');
        Route::patch('/routes/{id}', [LogisticsController::class, 'updateRoute'])->middleware('permission:delivery.update');
        Route::get('/vehicles', [LogisticsController::class, 'vehicles'])->middleware('permission:delivery.view');
        Route::post('/vehicles', [LogisticsController::class, 'storeVehicle'])->middleware('permission:delivery.create');
        Route::patch('/vehicles/{id}', [LogisticsController::class, 'updateVehicle'])->middleware('permission:delivery.update');
        Route::get('/drivers', [LogisticsController::class, 'drivers'])->middleware('permission:delivery.view');
        Route::post('/drivers', [LogisticsController::class, 'storeDriver'])->middleware('permission:delivery.create');
        Route::patch('/drivers/{id}', [LogisticsController::class, 'updateDriver'])->middleware('permission:delivery.update');
        Route::get('/carriers', [LogisticsController::class, 'carriers'])->middleware('permission:delivery.view');
        Route::get('/shipping-notes', [LogisticsController::class, 'shippingNotes'])->middleware('permission:delivery.view');
        Route::post('/shipping-notes', [LogisticsController::class, 'storeShippingNote'])->middleware('permission:delivery.prepare,delivery.create');
        Route::patch('/shipping-notes/{id}', [LogisticsController::class, 'updateShippingNote'])->middleware('permission:delivery.prepare,delivery.update');
        Route::patch('/shipping-notes/{id}/carrier', [LogisticsController::class, 'assignCarrier'])->middleware('permission:delivery.prepare,delivery.update,delivery.create');
        Route::post('/shipping-notes/{id}/label-printed', [LogisticsController::class, 'markLabelPrinted'])->middleware('permission:delivery.prepare,delivery.view');
        Route::post('/shipping-notes/{id}/load', [LogisticsController::class, 'loadShippingNote'])->middleware('permission:stock.issue,delivery.prepare');
    });

    Route::middleware('module:finance')->group(function () {
        Route::get('/finance/invoices', [FinanceController::class, 'invoices'])->middleware('permission:finance.view');
        Route::get('/finance/payments', [FinanceController::class, 'payments'])->middleware('permission:finance.view');
        Route::get('/finance/budgets', [FinanceController::class, 'budgets'])->middleware('permission:finance.budget');
        Route::get('/finance/expenses', [FinanceController::class, 'expenses'])->middleware('permission:finance.view');
        Route::post('/finance/invoices/{id}/pay', [FinanceController::class, 'payInvoice'])->middleware('permission:finance.payment');
    });

    Route::get('/tasks', [TaskController::class, 'index'])->middleware('permission:task.view');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('permission:task.create');
    Route::patch('/tasks/{id}', [TaskController::class, 'update'])->middleware('permission:task.update');

    Route::get('/alerts', [AlertController::class, 'index'])->middleware('permission:task.view,stock.view,delivery.view');
    Route::post('/alerts/{id}/read', [AlertController::class, 'markRead'])->middleware('permission:task.view,stock.view,delivery.view');
    Route::get('/planning', [AlertController::class, 'planning'])->middleware('permission:task.view');

    Route::get('/analytics/snapshot', [AnalyticsController::class, 'snapshot'])->middleware(['permission:analytics.view', 'module:analytics']);

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:user.view,account.admins');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:user.create,account.admins');
    Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:user.view,account.admins');
    Route::patch('/users/{id}', [UserController::class, 'update'])->middleware('permission:user.update,account.admins');
    Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:user.view,account.admins');

    Route::get('/platform/companies', [PlatformController::class, 'companies'])->middleware('permission:platform.companies,account.view');
    Route::post('/platform/companies', [PlatformController::class, 'storeCompany'])->middleware('permission:platform.companies');
    Route::patch('/platform/companies/{id}', [PlatformController::class, 'updateCompany'])->middleware('permission:platform.companies,account.company');
    Route::get('/platform/plans', [PlatformController::class, 'plans'])->middleware('permission:platform.plans,account.view');
    Route::post('/platform/plans', [PlatformController::class, 'storePlan'])->middleware('permission:platform.plans');
    Route::patch('/platform/plans/{id}', [PlatformController::class, 'updatePlan'])->middleware('permission:platform.plans');
    Route::get('/platform/subscriptions', [PlatformController::class, 'subscriptions'])->middleware('permission:platform.billing,account.billing');
    Route::get('/platform/invoices', [PlatformController::class, 'invoices'])->middleware('permission:platform.billing,account.billing');
    Route::patch('/platform/companies/{companyId}/subscription', [PlatformController::class, 'changeSubscription'])->middleware('permission:platform.billing,account.billing');
    Route::post('/platform/invoices/{id}/pay', [PlatformController::class, 'payInvoice'])->middleware('permission:platform.billing,account.billing');
    Route::post('/platform/billing/checkout', [BillingController::class, 'checkout'])->middleware('permission:platform.billing,account.billing');
    Route::post('/platform/billing/portal', [BillingController::class, 'portal'])->middleware('permission:platform.billing,account.billing');
});
