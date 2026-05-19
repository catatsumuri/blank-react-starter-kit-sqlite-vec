<?php

namespace App\Services;

class DocumentChunker
{
    private const MIN_CONTENT_LENGTH = 80;

    private const MAX_EMBED_CHARS = 12_000;

    /**
     * @return array<int, array{heading: ?string, content: string, embed_text: string}>
     */
    public function chunk(string $title, string $content): array
    {
        $sections = $this->splitIntoSections($content);
        $sections = $this->mergeShortSections($sections);
        $sections = $this->splitOversizedSections($sections);

        if ($sections !== []) {
            return array_values(array_map(
                fn (array $section): array => [
                    'heading' => $section['heading'],
                    'content' => $section['content'],
                    'embed_text' => $this->buildEmbedText($title, $section['heading'], $section['content']),
                ],
                $sections,
            ));
        }

        $body = trim(str_replace(["\r\n", "\r"], "\n", $content));

        return $body === '' ? [] : [[
            'heading' => null,
            'content' => $body,
            'embed_text' => $this->buildEmbedText($title, null, $body),
        ]];
    }

    /**
     * @return array<int, array{heading: ?string, content: string}>
     */
    private function splitIntoSections(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", trim($content));

        if ($content === '') {
            return [];
        }

        $sections = [];

        foreach (preg_split('/^(?=#{1,2} )/m', $content, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^#{1,2} ([^\n]+)\n?(.*)/su', $raw, $matches) === 1) {
                $sections[] = [
                    'heading' => trim($matches[1]),
                    'content' => trim($matches[2]),
                ];
                continue;
            }

            $sections[] = [
                'heading' => null,
                'content' => $raw,
            ];
        }
 
        return $sections;
    }

    /**
     * @param  array<int, array{heading: ?string, content: string}>  $sections
     * @return array<int, array{heading: ?string, content: string}>
     */
    private function mergeShortSections(array $sections): array
    {
        $result = [];

        foreach ($sections as $section) {
            if (
                $result !== []
                && mb_strlen($result[array_key_last($result)]['content']) < self::MIN_CONTENT_LENGTH
            ) {
                $last = array_pop($result);

                $section = [
                    'heading' => $last['heading'],
                    'content' => rtrim($last['content'])."\n\n".ltrim($section['content']),
                ];
            }

            $result[] = $section;
        }

        return $result;
    }

    /**
     * @param  array<int, array{heading: ?string, content: string}>  $sections
     * @return array<int, array{heading: ?string, content: string}>
     */
    private function splitOversizedSections(array $sections): array
    {
        $result = [];

        foreach ($sections as $section) {
            if (mb_strlen($section['content']) <= self::MAX_EMBED_CHARS) {
                $result[] = $section;
                continue;
            }

            $paragraphs = preg_split('/\n{2,}/u', $section['content'], flags: PREG_SPLIT_NO_EMPTY) ?: [];
            $buffer = '';

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);

                if ($paragraph === '') {
                    continue;
                }

                $candidate = $buffer === '' ? $paragraph : $buffer."\n\n".$paragraph;

                if (mb_strlen($candidate) > self::MAX_EMBED_CHARS) {
                    if ($buffer !== '') {
                        $result[] = [
                            'heading' => $section['heading'],
                            'content' => $buffer,
                        ];
                    }

                    $buffer = mb_substr($paragraph, 0, self::MAX_EMBED_CHARS);

                    continue;
                }

                $buffer = $candidate;
            }

            if ($buffer !== '') {
                $result[] = [
                    'heading' => $section['heading'],
                    'content' => $buffer,
                ];
            }
        }

        return $result;
    }

    private function buildEmbedText(string $title, ?string $heading, string $content): string
    {
        $parts = [$title];

        if ($heading !== null && $heading !== '') {
            $parts[] = '## '.$heading;
        }

        $parts[] = trim($content);

        return implode("\n\n", $parts);
    }
}
