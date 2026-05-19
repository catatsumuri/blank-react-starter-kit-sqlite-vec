<?php

namespace Database\Seeders;

use App\Models\Document;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Spatie\YamlFrontMatter\YamlFrontMatter;

class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $articleDir = database_path('zenn-article');
        $files = glob("{$articleDir}/*.md");

        $documents = [];
        foreach ($files as $file) {
            $parsed = YamlFrontMatter::parseFile($file);
            $matter = $parsed->matter();

            if (empty($matter['title'])) {
                continue;
            }

            $documents[] = [
                'slug' => pathinfo($file, PATHINFO_FILENAME),
                'title' => $matter['title'],
                'emoji' => $matter['emoji'] ?? '📄',
                'type' => $matter['type'] ?? 'tech',
                'topics' => json_encode(is_array($matter['topics'] ?? null) ? $matter['topics'] : []),
                'published' => (bool) ($matter['published'] ?? true),
                'published_at' => filled($matter['published_at'] ?? null)
                    ? Carbon::parse($matter['published_at'])
                    : null,
                'content' => trim($parsed->body()),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Document::query()->delete();
        if ($documents !== []) {
            Document::query()->insert($documents);
        }
    }
}
