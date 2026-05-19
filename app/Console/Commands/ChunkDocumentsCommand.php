<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\DocumentChunker;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'documents:chunk')]
class ChunkDocumentsCommand extends Command
{
    protected $signature = 'documents:chunk
        {--limit= : 先頭から何件処理するか}';

    protected $description = 'documents をチャンク分割して document_chunks に保存する';

    public function handle(DocumentChunker $chunker): int
    {
        $documents = Document::query()
            ->select(['id', 'title', 'content'])
            ->where('published', true)
            ->orderBy('id')
            ->when(
                $this->option('limit') !== null,
                fn ($q) => $q->limit((int) $this->option('limit'))
            )
            ->get();

        if ($documents->isEmpty()) {
            $this->warn('対象ドキュメントがありません。');

            return self::SUCCESS;
        }

        $this->info("Chunking {$documents->count()} documents...");

        $allChunks = [];
        $now = now();

        foreach ($documents as $document) {
            $chunks = $chunker->chunk($document->title, $document->content);

            foreach ($chunks as $index => $chunk) {
                $allChunks[] = [
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'heading' => $chunk['heading'],
                    'content' => $chunk['content'],
                    'embed_text' => $chunk['embed_text'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $totalChunks = count($allChunks);
        $average = round($totalChunks / $documents->count(), 1);

        $this->info("  -> {$totalChunks} chunks generated (avg {$average} per doc)");

        DocumentChunk::query()
            ->whereIn('document_id', $documents->pluck('id'))
            ->delete();

        foreach (array_chunk($allChunks, 200) as $batch) {
            DocumentChunk::query()->insert($batch);
        }

        $this->info("Saved {$totalChunks} chunks into [document_chunks].");

        return self::SUCCESS;
    }
}
