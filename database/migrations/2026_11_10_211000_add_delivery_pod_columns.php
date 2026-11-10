<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve de livraison (POD) en colonnes dédiées plutôt que payload opaque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->longText('signature_data_url')->nullable()->after('signed_at');
            $table->decimal('pod_lat', 10, 7)->nullable()->after('signature_data_url');
            $table->decimal('pod_lng', 10, 7)->nullable()->after('pod_lat');
            $table->timestamp('pod_at')->nullable()->after('pod_lng');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['signature_data_url', 'pod_lat', 'pod_lng', 'pod_at']);
        });
    }
};
