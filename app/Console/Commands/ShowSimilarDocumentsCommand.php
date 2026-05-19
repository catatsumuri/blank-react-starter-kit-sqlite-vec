<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'documents:similar')]
class ShowSimilarDocumentsCommand extends Command
{
    protected $signature = 'documents:similar
        {document_id : Document ID to find similar documents for}
        {--limit=5 : Number of results to display}
        {--per-chunk=20 : Number of nearest neighbors to fetch per chunk}';

    protected $description = 'Display the IDs and titles of documents similar to the specified document ID';

    public function handle(): int
    {
        $documentId = (int) $this->argument('document_id');
        $limit = max(1, (int) $this->option('limit'));
        $perChunk = max($limit * 3, (int) $this->option('per-chunk'));

        $document = Document::query()->find($documentId, ['id', 'title']);

        if ($document === null) {
            $this->error("Document ID {$documentId} does not exist.");

            return self::FAILURE;
        }

        $chunkIds = DocumentChunk::query()
            ->where('document_id', $documentId)
            ->orderBy('chunk_index')
            ->pluck('id');

        if ($chunkIds->isEmpty()) {
            $this->warn("No chunks found for document ID {$documentId}. Run documents:chunk first.");

            return self::SUCCESS;
        }

        if (! $this->embeddingTableExists()) {
            $this->error('The document_chunk_embeddings table does not exist. Check your migrations and sqlite-vec loading.');

            return self::FAILURE;
        }

        $this->info("Looking up documents similar to [{$document->id}] {$document->title}");

        $scores = [];

        try {
            foreach ($chunkIds as $chunkId) {
                $rows = DB::select(
                    <<<'SQL'
                    SELECT
                        dc.document_id,
                        d.title,
                        e.distance
                    FROM document_chunk_embeddings AS e
                    JOIN document_chunks AS dc ON dc.id = e.rowid
                    JOIN documents AS d ON d.id = dc.document_id
                    WHERE e.embedding MATCH (
                        SELECT embedding
                        FROM document_chunk_embeddings
                        WHERE rowid = ?
                    )
                      AND k = ?
                      AND dc.document_id != ?
                    ORDER BY e.distance ASC
                    LIMIT ?
                    SQL,
                    [$chunkId, $perChunk, $documentId, $perChunk]
                );

                foreach ($rows as $row) {
                    $targetId = (int) $row->document_id;
                    $distance = (float) $row->distance;

                    if (! isset($scores[$targetId])) {
                        $scores[$targetId] = [
                            'id' => $targetId,
                            'title' => $row->title,
                            'best_distance' => $distance,
                            'chunk_distances' => [$chunkId => $distance],
                        ];

                        continue;
                    }

                    $scores[$targetId]['best_distance'] = min(
                        $scores[$targetId]['best_distance'],
                        $distance
                    );

                    if (
                        ! isset($scores[$targetId]['chunk_distances'][$chunkId])
                        || $distance < $scores[$targetId]['chunk_distances'][$chunkId]
                    ) {
                        $scores[$targetId]['chunk_distances'][$chunkId] = $distance;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->error('Failed to search for similar documents.');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        if ($scores === []) {
            $this->warn('No similar document candidates were found.');

            return self::SUCCESS;
        }

        $scores = array_values(array_map(function (array $row): array {
            $distances = array_values($row['chunk_distances']);
            sort($distances);

            $row['hits'] = count($distances);
            $row['avg_distance'] = array_sum($distances) / $row['hits'];

            unset($row['chunk_distances']);

            return $row;
        }, $scores));

        usort($scores, function (array $left, array $right): int {
            $distanceOrder = $left['avg_distance'] <=> $right['avg_distance'];

            if ($distanceOrder !== 0) {
                return $distanceOrder;
            }

            $hitsOrder = $right['hits'] <=> $left['hits'];

            if ($hitsOrder !== 0) {
                return $hitsOrder;
            }

            $bestDistanceOrder = $left['best_distance'] <=> $right['best_distance'];

            if ($bestDistanceOrder !== 0) {
                return $bestDistanceOrder;
            }

            return $right['hits'] <=> $left['hits'];
        });

        $results = array_slice($scores, 0, $limit);

        $this->table(
            ['id', 'title', 'avg_distance', 'best_distance', 'hits'],
            array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'avg_distance' => number_format($row['avg_distance'], 6, '.', ''),
                    'best_distance' => number_format($row['best_distance'], 6, '.', ''),
                    'hits' => $row['hits'],
                ],
                $results,
            )
        );

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
