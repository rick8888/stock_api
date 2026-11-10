<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Statistiques de demande dérivées de stock_movements. Jamais saisies : recalculées
        // par replenishment:compute-demand. Granularité (article, entrepôt) car la
        // consommation d'un même article diffère d'un point de livraison à l'autre.
        Schema::create('product_demand_stats', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('product_id')->index();
            $table->string('warehouse_id')->index();
            $table->decimal('avg_daily_demand', 12, 4)->default(0);
            $table->decimal('demand_std_dev', 12, 4)->default(0);
            $table->unsignedInteger('sample_days')->default(0);
            $table->unsignedInteger('nonzero_days')->default(0);
            $table->integer('total_issued')->default(0);
            $table->string('method')->default('moving_average');
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id'], 'demand_stats_product_warehouse_unique');
        });

        // Catalogue fournisseur-article : c'est ici que vivent MOQ, colisage et prix réels,
        // qui n'existaient nulle part auparavant.
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('supplier_id')->index();
            $table->string('product_id')->index();
            $table->string('supplier_sku')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('MAD');
            $table->unsignedInteger('min_order_qty')->nullable();
            $table->unsignedInteger('order_multiple')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->boolean('active')->default(true);
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['supplier_id', 'product_id'], 'supplier_products_supplier_product_unique');
        });

        // Surcharge des conditions par point de livraison : un même fournisseur ne livre pas
        // Casablanca et Tanger dans le même délai.
        Schema::create('supplier_warehouse_terms', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('supplier_id')->index();
            $table->string('warehouse_id')->index();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('lead_time_std_dev', 8, 2)->nullable();
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['supplier_id', 'warehouse_id'], 'supplier_terms_supplier_warehouse_unique');
        });

        // Trace auditable de chaque proposition : on fige les paramètres d'entrée pour
        // pouvoir expliquer a posteriori pourquoi telle quantité a été proposée.
        Schema::create('replenishment_suggestions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('product_id')->index();
            $table->string('warehouse_id')->index();
            $table->string('supplier_id')->nullable()->index();
            $table->string('policy')->default('rop');
            $table->decimal('avg_daily_demand', 12, 4)->default(0);
            $table->decimal('demand_std_dev', 12, 4)->default(0);
            $table->decimal('lead_time_days', 8, 2)->default(0);
            $table->decimal('lead_time_std_dev', 8, 2)->default(0);
            $table->decimal('service_level', 5, 4)->nullable();
            $table->integer('safety_stock')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->integer('target_level')->default(0);
            $table->integer('available')->default(0);
            $table->integer('in_transit')->default(0);
            $table->integer('pending_need')->default(0);
            $table->integer('raw_quantity')->default(0);
            $table->integer('suggested_quantity')->default(0);
            $table->unsignedInteger('min_order_qty')->nullable();
            $table->unsignedInteger('order_multiple')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->string('risk')->default('low');
            $table->json('reasons')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->string('company_id')->nullable()->index();
            $table->timestamps();
            $table->index(['warehouse_id', 'computed_at'], 'replenishment_warehouse_computed_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('safety_stock')->nullable()->after('min_stock');
            $table->decimal('service_level', 5, 4)->nullable()->after('safety_stock');
            $table->string('abc_class', 1)->nullable()->index()->after('service_level');
            $table->string('replenishment_policy')->nullable()->after('abc_class');
            $table->unsignedInteger('review_period_days')->nullable()->after('replenishment_policy');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->decimal('lead_time_std_dev', 8, 2)->nullable()->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('lead_time_std_dev');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'safety_stock', 'service_level', 'abc_class', 'replenishment_policy', 'review_period_days',
            ]);
        });

        Schema::dropIfExists('replenishment_suggestions');
        Schema::dropIfExists('supplier_warehouse_terms');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('product_demand_stats');
    }
};
