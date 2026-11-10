<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socle du suivi temps réel : journal de pings GPS + dernière position
 * dénormalisée sur véhicule et tournée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('company_id')->nullable()->index();
            $table->string('subject_type'); // route | vehicle | delivery | shipment
            $table->string('subject_id')->index();
            $table->string('route_id')->nullable()->index();
            $table->string('vehicle_id')->nullable()->index();
            $table->string('driver_id')->nullable()->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('event_type')->default('ping'); // ping | departed | arrived | delivered | failed
            $table->string('source')->default('driver_app'); // driver_app | device | manual | system
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'recorded_at']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('last_lat', 10, 7)->nullable()->after('status');
            $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            $table->timestamp('last_located_at')->nullable()->after('last_lng');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->decimal('last_lat', 10, 7)->nullable()->after('progress');
            $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            $table->timestamp('last_located_at')->nullable()->after('last_lng');
            $table->timestamp('eta_at')->nullable()->after('last_located_at');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['last_lat', 'last_lng', 'last_located_at', 'eta_at']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['last_lat', 'last_lng', 'last_located_at']);
        });
        Schema::dropIfExists('tracking_events');
    }
};
