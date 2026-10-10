<?php

namespace UWMadison\MailMerge;

/**
 * @psalm-suppress UndefinedClass
 * @noinspection PhpUndefinedClassInspection
 */

use ZipArchive;

class DocxEngine
{
    public static function extractTags($filePath): array
    {
        if (!file_exists($filePath)) return [];
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return [];

        $tags = [];
        $xmlFiles = ['word/document.xml'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('/^word\/(header|footer)\d+\.xml$/', $name))
                $xmlFiles[] = $name;
        }

        foreach ($xmlFiles as $xmlFile) {
            $content = $zip->getFromName($xmlFile);
            if ($content === false) continue;

            if (preg_match_all('/<w:fldSimple[^>]+w:instr=["\'][^"\']*MERGEFIELD\s+([^\s"\'\\]+)/i', $content, $m))
                foreach ($m[1] as $t) $tags[] = trim($t);

            if (preg_match_all('/<w:instrText[^>]*>\s*MERGEFIELD\s+([^\s"\'\\]+)/i', $content, $m))
                foreach ($m[1] as $t) $tags[] = trim($t);

            if (preg_match_all('/(?:«|&laquo;|\{\{|\[)([a-zA-Z0-9_\-\.]+)(?:»|&raquo;|\}\}|\])/u', $content, $m))
                foreach ($m[1] as $t) $tags[] = trim($t);
        }

        $zip->close();
        return array_values(array_unique(array_filter($tags)));
    }

    public static function merge($templatePath, $data, $outputPath): bool
    {
        if (!file_exists($templatePath)) return false;
        if (!copy($templatePath, $outputPath)) return false;

        $zip = new ZipArchive();
        if ($zip->open($outputPath) !== true) return false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'word/document.xml' || preg_match('/^word\/(header|footer)\d+\.xml$/', $name)) {
                $content = $zip->getFromName($name);
                if ($content === false) continue;
                $merged = self::replaceXmlPlaceholders($content, $data);
                $zip->addFromString($name, $merged);
            }
        }

        return $zip->close();
    }

    private static function replaceXmlPlaceholders($xml, $data): string
    {
        // 1. Handle w:fldSimple MERGEFIELDs: preserve run formatting if present
        $xml = preg_replace_callback(
            '/<w:fldSimple[^>]+w:instr=["\'][^"\']*MERGEFIELD\s+([^\s"\'\\]+)[^"\']*["\'][^>]*>(.*?)<\/w:fldSimple>/is',
            function ($m) use ($data) {
                $tag = trim($m[1]);
                $inner = $m[2];
                $val = htmlspecialchars($data[$tag] ?? '', ENT_XML1, 'UTF-8');

                // Extract run properties (<w:rPr>...</w:rPr>) from the inner content if present
                $rPr = '';
                if (preg_match('/<w:rPr>.*?<\/w:rPr>/is', $inner, $pMatch)) {
                    $rPr = $pMatch[0];
                }
                return "<w:r>{$rPr}<w:t xml:space=\"preserve\">{$val}</w:t></w:r>";
            },
            $xml
        );

        // 2. Safely replace variables inside text nodes (<w:t>...</w:t>)
        $xml = preg_replace_callback('/<w:t(\b[^>]*)>(.*?)<\/w:t>/is', function ($tMatch) use ($data) {
            $attrs = $tMatch[1];
            $text = $tMatch[2];

            foreach ($data as $key => $val) {
                $cleanVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                $patterns = [
                    '/(?:«|&laquo;)' . preg_quote($key, '/') . '(?:»|&raquo;)/u',
                    '/\{\{' . preg_quote($key, '/') . '\}\}/u',
                    '/\{' . preg_quote($key, '/') . '\}/u',
                    '/\[' . preg_quote($key, '/') . '\]/u',
                ];
                foreach ($patterns as $pattern) {
                    $text = preg_replace($pattern, $cleanVal, $text);
                }
            }

            if (!str_contains($attrs, 'xml:space')) {
                $attrs .= ' xml:space="preserve"';
            }

            return "<w:t{$attrs}>{$text}</w:t>";
        }, $xml);

        return $xml;
    }
}
