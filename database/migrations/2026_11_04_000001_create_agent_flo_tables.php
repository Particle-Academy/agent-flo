<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flo_delegations', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('tenant_key')->index();
            $table->json('scope');
            $table->boolean('tainted')->default(false);
            $table->json('tools');
            $table->json('drafts');
            $table->timestamps();
        });

        Schema::create('flo_proposals', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('tenant_key')->index();
            $table->string('delegation_id', 26)->index();
            $table->string('kind');
            $table->string('target')->nullable();
            $table->string('based_on')->nullable();
            $table->json('schema');
            $table->json('diff');
            $table->string('title');
            $table->text('rationale')->nullable();
            $table->string('status')->index();
            $table->json('created_scope');
            $table->json('resolved_scope')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flo_proposals');
        Schema::dropIfExists('flo_delegations');
    }
};
