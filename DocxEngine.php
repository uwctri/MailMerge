<?php

namespace UWMadison\MailMerge;

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

            if (preg_match_all('/<w:fldSimple[^>]+w:instr=["\'][^"\']*MERGEFIELD\s+["\']?([^"\'\s\\\\]+)["\']?/i', $content, $m))
                foreach ($m[1] as $t) $tags[] = trim($t);

            if (preg_match_all('/<w:instrText[^>]*>\s*MERGEFIELD\s+["\']?([^"\'\s\\\\]+)["\']?/i', $content, $m))
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
                // Safety guard: never overwrite with empty content
                if (!empty($merged) && strlen($merged) > 10) {
                    $zip->addFromString($name, $merged);
                }
            }
        }

        return $zip->close();
    }

    private static function replaceXmlPlaceholders($xml, $data): string
    {
        // 1. Handle Word complex MERGEFIELDs:
        // <w:r>...<w:fldChar w:fldCharType="begin"/>...</w:r>
        // <w:r>...<w:instrText...> MERGEFIELD Tag ...</w:instrText>...</w:r>
        // <w:r>...<w:fldChar w:fldCharType="separate"/>...</w:r>
        // ...<w:t>«Tag»</w:t>...
        // <w:r>...<w:fldChar w:fldCharType="end"/>...</w:r>
        $complexPattern = '/<w:r\b[^>]*>(?:(?!<w:r\b).)*?<w:fldChar\s+w:fldCharType="begin"\/>.*?<\/w:r>\s*<w:r\b[^>]*>(?:(?!<w:r\b).)*?<w:instrText[^>]*>\s*MERGEFIELD\s+["\']?([^"\'\s\\\\]+)["\']?.*?<\/w:instrText>.*?<\/w:r>\s*<w:r\b[^>]*>(?:(?!<w:r\b).)*?<w:fldChar\s+w:fldCharType="separate"\/>.*?<\/w:r>(.*?)<w:r\b[^>]*>(?:(?!<w:r\b).)*?<w:fldChar\s+w:fldCharType="end"\/>.*?<\/w:r>/is';
        $xmlMerged = preg_replace_callback(
            $complexPattern,
            function ($m) use ($data) {
                $tag = trim($m[1]);
                $disp = $m[2];
                $val = htmlspecialchars((string)($data[$tag] ?? ''), ENT_XML1, 'UTF-8');
                $val = str_replace(["\r\n", "\r", "\n"], '</w:t><w:br/><w:t xml:space="preserve">', $val);
                $rPr = '';
                if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>/is', $disp, $pMatch)) {
                    $rPr = $pMatch[0];
                } else if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>/is', $m[0], $pMatch)) {
                    $rPr = $pMatch[0];
                }
                return "<w:r>{$rPr}<w:t xml:space=\"preserve\">{$val}</w:t></w:r>";
            },
            $xml
        );
        if ($xmlMerged !== null) {
            $xml = $xmlMerged;
        }

        // 2. Handle w:fldSimple MERGEFIELDs: preserve run formatting if present
        $simplePattern = '/<w:fldSimple[^>]+w:instr=["\'][^"\']*MERGEFIELD\s+["\']?([^"\'\s\\\\]+)["\']?[^"\']*["\'][^>]*>(.*?)<\/w:fldSimple>/is';
        $xmlMerged = preg_replace_callback(
            $simplePattern,
            function ($m) use ($data) {
                $tag = trim($m[1]);
                $inner = $m[2];
                $val = htmlspecialchars((string)($data[$tag] ?? ''), ENT_XML1, 'UTF-8');
                $val = str_replace(["\r\n", "\r", "\n"], '</w:t><w:br/><w:t xml:space="preserve">', $val);

                // Extract run properties (<w:rPr>...</w:rPr>) from the inner content if present
                $rPr = '';
                if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>/is', $inner, $pMatch)) {
                    $rPr = $pMatch[0];
                } else if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>/is', $m[0], $pMatch)) {
                    $rPr = $pMatch[0];
                }
                return "<w:r>{$rPr}<w:t xml:space=\"preserve\">{$val}</w:t></w:r>";
            },
            $xml
        );
        if ($xmlMerged !== null) {
            $xml = $xmlMerged;
        }

        // 3. Safely replace variables inside text nodes (<w:t>...</w:t>)
        $xmlMerged = preg_replace_callback('/<w:t(\b[^>]*)>(.*?)<\/w:t>/is', function ($tMatch) use ($data) {
            $attrs = $tMatch[1];
            $text = $tMatch[2];

            foreach ($data as $key => $val) {
                $cleanVal = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                $cleanVal = str_replace(["\r\n", "\r", "\n"], '</w:t><w:br/><w:t xml:space="preserve">', $cleanVal);
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
        if ($xmlMerged !== null) {
            $xml = $xmlMerged;
        }

        return $xml;
    }
}
