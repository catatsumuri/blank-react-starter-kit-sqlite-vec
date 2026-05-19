<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EMBEDDING_DIMENSIONS = 1024;

    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chunk_index');
            $table->string('heading')->nullable();
            $table->text('content');
            $table->text('embed_text');
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
        });

        if (! $this->sqliteVecModuleAvailable()) {
            return;
        }

        DB::statement(sprintf(
            'CREATE VIRTUAL TABLE IF NOT EXISTS document_chunk_embeddings USING vec0(embedding float[%d])',
            self::EMBEDDING_DIMENSIONS,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS document_chunk_embeddings');
        Schema::dropIfExists('document_chunks');
    }

    private function sqliteVecModuleAvailable(): bool
    {
        try {
            return (bool) DB::scalar(
                "SELECT 1 FROM pragma_module_list WHERE name = 'vec0' LIMIT 1"
            );
        } catch (\Throwable) {
            return false;
        }
    }
};
