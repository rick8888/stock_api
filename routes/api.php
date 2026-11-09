<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\LogisticsController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/categories', [ProductController::class, 'categories']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::patch('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);

    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show']);
    Route::patch('/warehouses/{id}', [WarehouseController::class, 'update']);

    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::post('/suppliers', [SupplierController::class, 'store']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::patch('/suppliers/{id}', [SupplierController::class, 'update']);

    Route::get('/stock', [StockController::class, 'index']);
    Route::get('/stock/movements', [StockController::class, 'movements']);
    Route::get('/stock/inventories', [StockController::class, 'inventories']);
    Route::post('/stock/inventories', [StockController::class, 'startInventory']);
    Route::patch('/stock/inventories/{id}', [StockController::class, 'saveInventory']);
    Route::post('/stock/inventories/{id}/submit', [StockController::class, 'submitInventory']);
    Route::post('/stock/inventories/{id}/complete', [StockController::class, 'completeInventory']);
    Route::post('/stock/receive', [StockController::class, 'receive']);
    Route::post('/stock/issue', [StockController::class, 'issue']);
    Route::post('/stock/transfer', [StockController::class, 'transfer']);
    Route::post('/stock/adjust', [StockController::class, 'adjust']);
    Route::patch('/stock/assignment', [StockController::class, 'assignment']);
    Route::post('/stock/close-day', [StockController::class, 'closeDay']);
    Route::post('/stock/return', [StockController::class, 'returnStock']);

    Route::get('/procurement', [ProcurementController::class, 'index']);
    Route::post('/procurement', [ProcurementController::class, 'store']);
    Route::patch('/procurement/{id}', [ProcurementController::class, 'update']);

    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::patch('/purchases/{id}', [PurchaseController::class, 'update']);

    Route::get('/consultations', [ConsultationController::class, 'index']);
    Route::post('/consultations', [ConsultationController::class, 'store']);
    Route::patch('/consultations/{id}', [ConsultationController::class, 'update']);

    Route::get('/receipts', [ReceiptController::class, 'index']);
    Route::post('/receipts', [ReceiptController::class, 'store']);
    Route::post('/receipts/{id}/validate', [ReceiptController::class, 'validateReceipt']);

    Route::get('/deliveries', [DeliveryController::class, 'index']);
    Route::post('/deliveries', [DeliveryController::class, 'store']);
    Route::patch('/deliveries/{id}', [DeliveryController::class, 'update']);
    Route::patch('/deliveries/{id}/status', [DeliveryController::class, 'updateStatus']);

    Route::get('/shipments', [LogisticsController::class, 'shipments']);
    Route::post('/shipments', [LogisticsController::class, 'storeShipment']);
    Route::patch('/shipments/{id}', [LogisticsController::class, 'updateShipment']);
    Route::get('/routes', [LogisticsController::class, 'routes']);
    Route::post('/routes', [LogisticsController::class, 'storeRoute']);
    Route::patch('/routes/{id}', [LogisticsController::class, 'updateRoute']);
    Route::get('/vehicles', [LogisticsController::class, 'vehicles']);
    Route::post('/vehicles', [LogisticsController::class, 'storeVehicle']);
    Route::patch('/vehicles/{id}', [LogisticsController::class, 'updateVehicle']);
    Route::get('/drivers', [LogisticsController::class, 'drivers']);
    Route::post('/drivers', [LogisticsController::class, 'storeDriver']);
    Route::patch('/drivers/{id}', [LogisticsController::class, 'updateDriver']);
    Route::get('/shipping-notes', [LogisticsController::class, 'shippingNotes']);
    Route::post('/shipping-notes', [LogisticsController::class, 'storeShippingNote']);
    Route::patch('/shipping-notes/{id}', [LogisticsController::class, 'updateShippingNote']);
    Route::post('/shipping-notes/{id}/load', [LogisticsController::class, 'loadShippingNote']);

    Route::get('/finance/invoices', [FinanceController::class, 'invoices']);
    Route::get('/finance/payments', [FinanceController::class, 'payments']);
    Route::get('/finance/budgets', [FinanceController::class, 'budgets']);
    Route::get('/finance/expenses', [FinanceController::class, 'expenses']);
    Route::post('/finance/invoices/{id}/pay', [FinanceController::class, 'payInvoice']);

    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/{id}/read', [AlertController::class, 'markRead']);
    Route::get('/planning', [AlertController::class, 'planning']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::patch('/users/{id}', [UserController::class, 'update']);
    Route::get('/roles', [UserController::class, 'roles']);

    Route::get('/platform/companies', [PlatformController::class, 'companies']);
    Route::post('/platform/companies', [PlatformController::class, 'storeCompany']);
    Route::patch('/platform/companies/{id}', [PlatformController::class, 'updateCompany']);
    Route::get('/platform/plans', [PlatformController::class, 'plans']);
    Route::post('/platform/plans', [PlatformController::class, 'storePlan']);
    Route::patch('/platform/plans/{id}', [PlatformController::class, 'updatePlan']);
    Route::get('/platform/subscriptions', [PlatformController::class, 'subscriptions']);
    Route::get('/platform/invoices', [PlatformController::class, 'invoices']);
    Route::patch('/platform/companies/{companyId}/subscription', [PlatformController::class, 'changeSubscription']);
    Route::post('/platform/invoices/{id}/pay', [PlatformController::class, 'payInvoice']);
});
