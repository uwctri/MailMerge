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
        $xml = preg_replace_callback(
            '/<w:fldSimple[^>]+w:instr=["\'][^"\']*MERGEFIELD\s+([^\s"\'\\]+)[^"\']*["\'][^>]*>.*?<\/w:fldSimple>/is',
            function ($m) use ($data) {
                $val = htmlspecialchars($data[trim($m[1])] ?? '', ENT_XML1, 'UTF-8');
                return "<w:r><w:t>{$val}</w:t></w:r>";
            },
            $xml
        );

        foreach ($data as $key => $val) {
            $cleanVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
            $patterns = [
                '/(?:«|&laquo;)' . preg_quote($key, '/') . '(?:»|&raquo;)/u',
                '/\{\{' . preg_quote($key, '/') . '\}\}/u',
                '/\{' . preg_quote($key, '/') . '\}/u',
                '/\[' . preg_quote($key, '/') . '\]/u',
            ];
            foreach ($patterns as $pattern)
                $xml = preg_replace($pattern, $cleanVal, $xml);
        }

        return $xml;
    }
}
