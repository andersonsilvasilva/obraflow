<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de referência = fonte + competência (YYYY-MM) + UF + regime de encargos (D-P03, D-P29).
 * Cada arquivo SINAPI traz exatamente uma competência, uma localidade e um regime. Global (sem tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('reference_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->char('version', 7); // competência YYYY-MM
            $table->char('state', 2);
            $table->string('location_name', 100)->nullable();
            $table->string('charges_regime', 20);
            $table->string('status', 20)->default('importing');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'version', 'state', 'charges_regime'], 'refds_identity_unique');
            $table->index(['status', 'state'], 'refds_status_idx');
        });

        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT chk_refds_version CHECK (REGEXP_LIKE(version, '^[0-9]{4}-(0[1-9]|1[0-2])$'))");
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT chk_refds_state CHECK (REGEXP_LIKE(state, '^[A-Z]{2}$', 'c'))");
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT chk_refds_regime CHECK (charges_regime IN ('with_relief','without_relief','not_applicable'))");
        DB::statement("ALTER TABLE reference_datasets ADD CONSTRAINT chk_refds_status CHECK (status IN ('importing','active','superseded','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_datasets');
    }
};
