<?php

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'documents:embed')]
class EmbedDocumentsCommand extends Command
{
    private const EMBEDDING_DIMENSIONS = 1024;

    protected $signature = 'documents:embed
        {--provider=bedrock : AI provider used for embedding generation}
        {--model= : Model used for embedding generation}
        {--limit= : Number of records to process from the beginning}
        {--fresh : Delete existing embeddings before reinserting them}';

    protected $description = 'Generate embeddings for document_chunks and save them to document_chunk_embeddings';

    public function handle(): int
    {
        $chunks = DocumentChunk::query()
            ->select(['id', 'embed_text'])
            ->orderBy('id')
            ->when(
                $this->option('limit') !== null,
                fn ($q) => $q->limit((int) $this->option('limit'))
            )
            ->get();

        if ($chunks->isEmpty()) {
            $this->warn('No chunks found to process. Run documents:chunk first.');

            return self::SUCCESS;
        }

        if (! config('database.connections.sqlite.vec_enabled', false)) {
            $this->error('sqlite-vec is disabled. Set SQLITE_VEC_AUTOLOAD=true.');

            return self::FAILURE;
        }

        if (! $this->embeddingTableExists()) {
            $this->error('The document_chunk_embeddings table does not exist. Check your migrations and sqlite-vec loading.');

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');
        $model = $this->option('model');
        $totalChunks = $chunks->count();

        $this->info("Embedding {$totalChunks} chunks with provider [{$provider}]...");

        $progress = $this->output->createProgressBar($totalChunks);
        $progress->start();

        $vectors = [];
        $dimensions = null;

        foreach ($chunks as $chunk) {
            try {
                $response = Embeddings::for([$chunk->embed_text])
                    ->generate($provider, is_string($model) && $model !== '' ? $model : null);
            } catch (Throwable $e) {
                $progress->finish();
                $this->newLine(2);
                $this->error("Failed to generate an embedding for chunk ID {$chunk->id}.");
                $this->line($e->getMessage());

                return self::FAILURE;
            }

            $vector = $response->first();
            $currentDimensions = count($vector);

            if ($dimensions === null) {
                $dimensions = $currentDimensions;
            } elseif ($dimensions !== $currentDimensions) {
                throw new RuntimeException("Embedding dimensions mismatch: expected={$dimensions}, actual={$currentDimensions}");
            }

            if ($currentDimensions !== self::EMBEDDING_DIMENSIONS) {
                throw new RuntimeException(
                    'Embedding dimensions do not match the table definition: '
                    ."expected=".self::EMBEDDING_DIMENSIONS.", actual={$currentDimensions}"
                );
            }

            $vectors[] = [
                'id' => $chunk->id,
                'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
            ];

            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        if ($dimensions === null) {
            $this->warn('No embedding vectors were generated.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('fresh')) {
            DB::delete('DELETE FROM document_chunk_embeddings');
        }

        foreach ($vectors as $row) {
            DB::delete('DELETE FROM document_chunk_embeddings WHERE rowid = ?', [$row['id']]);
            DB::insert(
                'INSERT INTO document_chunk_embeddings(rowid, embedding) VALUES(?, ?)',
                [$row['id'], $row['embedding']]
            );
        }

        $this->info("Saved {$totalChunks} embeddings into [document_chunk_embeddings] ({$dimensions} dimensions).");

        return self::SUCCESS;
    }

    private function embeddingTableExists(): bool
    {
        return DB::table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'document_chunk_embeddings')
            ->exists();
    }
}
