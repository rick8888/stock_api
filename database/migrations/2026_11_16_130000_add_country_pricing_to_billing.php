<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarifs par pays : pays de facturation sur l'entreprise, grille de prix par pays sur le plan,
 * devise sur chaque facture. L'existant est rattaché au Maroc (MAD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('country', 2)->default('MA')->index()->after('city');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->json('prices')->nullable()->after('yearly_price');
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('currency', 3)->default('MAD')->after('amount');
        });

        // Les prix existants deviennent le tarif Maroc.
        DB::table('subscription_plans')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                if (! empty($payload['prices'])) {
                    continue;
                }
                $ma = [
                    'monthly' => (int) ($row->monthly_price ?? $payload['monthlyPrice'] ?? 0),
                    'yearly' => (int) ($row->yearly_price ?? $payload['yearlyPrice'] ?? 0),
                ];
                foreach (['stripePriceMonthly' => 'stripe_price_monthly', 'stripePriceYearly' => 'stripe_price_yearly'] as $key => $column) {
                    $value = $row->{$column} ?? $payload[$key] ?? '';
                    if ($value) {
                        $ma[$key] = $value;
                    }
                }
                $prices = ['MA' => $ma];
                $payload['prices'] = $prices;
                DB::table('subscription_plans')->where('id', $row->id)->update([
                    'prices' => json_encode($prices),
                    'payload' => json_encode($payload),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('prices');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
