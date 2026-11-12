<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class SourceExtractor
{
    public function file(string $path, string $name): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $parts = match ($extension) {
            'txt' => in_array($mime, ['text/plain', 'application/octet-stream'], true) ? [['locator' => 'Text', 'text' => $this->utf8(file_get_contents($path))]] : throw new RuntimeException('The file is not a plain-text document.'),
            'docx' => in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true) ? $this->docx($path) : throw new RuntimeException('The file is not a valid DOCX document.'),
            'pdf' => $mime === 'application/pdf' ? $this->pdf($path) : throw new RuntimeException('The file is not a valid PDF document.'),
            default => throw new RuntimeException('Upload TXT, text PDF, or DOCX files only.'),
        };
        return $this->normalize($parts) + ['mime' => $mime];
    }

    private function utf8(string $text): string
    {
        if (str_starts_with($text, "\xff\xfe")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
        elseif (str_starts_with($text, "\xfe\xff")) $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0e-\x1f]/', $text)) throw new RuntimeException('Use readable UTF-8 or UTF-16 text without binary data.');
        return ltrim($text, "\xef\xbb\xbf");
    }

    private function docx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('DOCX extraction is unavailable. Ask the administrator to enable PHP ZIP.');
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) throw new RuntimeException('The DOCX archive cannot be read.');
        try {
            if ($zip->numFiles > 1000) throw new RuntimeException('The DOCX archive contains too many entries.');
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $size += $entry['size']; $name = strtolower($entry['name']);
                if ($size > 52428800 || $entry['size'] > 5242880 || ($entry['encryption_method'] ?? 0) !== 0
                    || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/')
                    || preg_match('/vbaproject|activex|embeddings\/|\.exe$|\.dll$|\.bin$|\.js$|\.vbs$|\.ps1$/', $name)) {
                    throw new RuntimeException('DOCX macros, embedded programs, encrypted archives, or oversized entries are not supported.');
                }
            }
            $types = $zip->getFromName('[Content_Types].xml');
            $xml = $zip->getFromName('word/document.xml');
            if (!$types || !$xml || str_contains(strtolower($types), 'macroenabled') || preg_match('/<!doctype|<!entity/i', $types.$xml)) throw new RuntimeException('The DOCX structure is invalid or contains active content.');
            $typeDocument = new DOMDocument;
            if (!@$typeDocument->loadXML($types, LIBXML_NONET)) throw new RuntimeException('The DOCX content types cannot be read.');
            $typeXPath = new DOMXPath($typeDocument);
            $typeXPath->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
            if (!$typeXPath->query('/t:Types/t:Override[@PartName="/word/document.xml"][@ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length) throw new RuntimeException('The archive is not a standard DOCX document.');
            $doc = new DOMDocument;
            if (!@$doc->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('The DOCX document XML cannot be read.');
            $xpath = new DOMXPath($doc); $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $parts = [];
            foreach ($xpath->query('//w:body//w:p') as $index => $paragraph) {
                $text = '';
                foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph) as $node) $text .= $node->localName === 't' ? $node->textContent : ' ';
                $parts[] = ['locator' => 'Paragraph '.($index + 1), 'text' => $text];
            }
            return $parts;
        } finally { $zip->close(); }
    }

    private function pdf(string $path): array
    {
        $process = new Process([config('sources.python'), '-B', base_path('scripts/extract_pdf.py'), $path], base_path(), ['PYTHONDONTWRITEBYTECODE' => '1']);
        $process->setTimeout(30);
        try { $process->run(); } catch (\Throwable) { throw new RuntimeException('The PDF could not be extracted within the processing limit.'); }
        $data = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !isset($data['parts'])) throw new RuntimeException($data['error'] ?? 'PDF extraction is unavailable or the document is unreadable.');
        return $data['parts'];
    }

    public function web(string $body, string $mime): array
    {
        if ($mime === 'text/plain') return $this->normalize([['locator' => 'Text', 'text' => $this->utf8($body)]]);
        if (!mb_check_encoding($body, 'UTF-8')) throw new RuntimeException('This web page uses an unsupported text encoding.');
        $doc = new DOMDocument;
        if (!@$doc->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('The web page HTML cannot be read.');
        $xpath = new DOMXPath($doc);
        $title = trim($xpath->evaluate('string(//title)'));
        foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//form|//iframe|//object|//embed|//svg|//template|//*[@hidden]|//*[@aria-hidden="true"]') as $node) $node->parentNode?->removeChild($node);
        $root = $xpath->query('//article')->item(0) ?? $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        $parts = [];
        if ($root) {
            foreach ($xpath->query('.//h1|.//h2|.//h3|.//p|.//li|.//pre|.//blockquote|.//td', $root) as $index => $node) {
                // Parent blocks containing another selected block would duplicate the same text.
                if ($xpath->query('.//p|.//li|.//pre|.//blockquote|.//td', $node)->length) continue;
                $parts[] = ['locator' => 'Block '.($index + 1), 'text' => $node->textContent];
            }
            if (!$parts) $parts[] = ['locator' => 'Page text', 'text' => $root->textContent];
        }
        return $this->normalize($parts) + ['title' => mb_substr($title, 0, 200)];
    }

    public function normalize(array $parts): array
    {
        $text = ''; $segments = [];
        foreach ($parts as $part) {
            $value = trim(preg_replace('/\s+/u', ' ', $part['text']) ?? '');
            if ($value === '') continue;
            if (mb_strlen($text) + mb_strlen($value) > config('sources.max_text')) throw new RuntimeException('The source exceeds 150,000 extracted characters. Use a shorter source.');
            for ($offset = 0; $offset < mb_strlen($value); $offset += 2000) {
                $chunk = mb_substr($value, $offset, 2000);
                if ($text !== '') $text .= "\n\n";
                $start = mb_strlen($text); $text .= $chunk;
                $segments[] = ['ordinal' => count($segments) + 1, 'locator' => $part['locator'], 'page' => $part['page'] ?? null,
                    'start' => $start, 'end' => mb_strlen($text), 'text' => $chunk];
                if (count($segments) > 500) throw new RuntimeException('This source contains too many text sections. Use a shorter document.');
            }
        }
        if (mb_strlen($text) < 10) throw new RuntimeException('No usable text was found in this source.');
        if (mb_strlen($text) > config('sources.max_text')) throw new RuntimeException('The source exceeds 150,000 extracted characters. Use a shorter source.');
        return ['text' => $text, 'segments' => $segments];
    }
}
