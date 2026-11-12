<?php

namespace Tests\Feature;

use App\Services\PublicSourceFetcher;
use App\Services\SourceExtractor;
use App\Services\SourceScanner;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class SourceSafetyTest extends TestCase
{
    public function test_reserved_addresses_mixed_dns_and_unsafe_url_ports_are_blocked(): void
    {
        $fetcher = new PublicSourceFetcher;
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::'] as $ip) $this->assertFalse($fetcher->publicIp($ip), $ip);
        foreach (['1.1.1.1', '8.8.8.8', '2606:4700:4700::1111'] as $ip) $this->assertTrue($fetcher->publicIp($ip), $ip);
        $mixed = new class extends PublicSourceFetcher {
            protected function resolve(string $host): array { return ['1.1.1.1', '10.0.0.1']; }
        };
        foreach (['https://example.com/', 'https://example.com:8080/', 'file:///etc/passwd', 'https://user:pass@example.com/'] as $url) {
            try { $mixed->target($url); $this->fail('Unsafe URL accepted: '.$url); } catch (RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }

    public function test_every_redirect_is_resolved_and_checked_before_connection(): void
    {
        $fetcher = new class extends PublicSourceFetcher {
            public array $requested = [];
            protected function resolve(string $host): array { return $host === 'example.com' ? ['1.1.1.1'] : ['169.254.169.254']; }
            protected function request(string $url, string $host, int $port, string $ip): array {
                $this->requested[] = [$host, $ip]; return [302, ['location' => 'http://metadata.example/latest'], ''];
            }
        };
        try { $fetcher->fetch('https://example.com/start'); $this->fail('Private redirect followed'); } catch (RuntimeException) { $this->assertSame([['example.com', '1.1.1.1']], $fetcher->requested); }
    }

    public function test_unsupported_web_types_and_redirect_loops_are_rejected(): void
    {
        foreach ([false, true] as $loop) {
            $fetcher = new class($loop) extends PublicSourceFetcher {
                public function __construct(private bool $loop) {}
                protected function resolve(string $host): array { return ['1.1.1.1']; }
                protected function request(string $url, string $host, int $port, string $ip): array {
                    return $this->loop ? [302, ['location' => '/again'], ''] : [200, ['content-type' => 'application/octet-stream'], 'binary'];
                }
            };
            try { $fetcher->fetch('https://example.com/start'); $this->fail('Unsafe response accepted'); } catch (RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }

    public function test_scanner_configuration_fails_closed(): void
    {
        config(['sources.scanner_binary' => base_path('does-not-exist.exe')]);
        $this->expectException(RuntimeException::class);
        (new SourceScanner)->scan(__FILE__);
    }

    public function test_real_docx_parser_extracts_paragraphs_and_rejects_macros_and_entities(): void
    {
        $directory = storage_path('framework/testing/source-parser');
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $path = $directory.'/reference.docx';
        try {
            foreach (['valid', 'macro', 'entity'] as $kind) {
                $zip = new ZipArchive; $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
                $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Approved DOCX reference paragraph.</w:t></w:r></w:p></w:body></w:document>';
                if ($kind === 'entity') $xml = '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///private">]>'.$xml;
                $zip->addFromString('word/document.xml', $xml);
                if ($kind === 'macro') $zip->addFromString('word/vbaProject.bin', 'macro bytes');
                $zip->close();
                try {
                    $result = (new SourceExtractor)->file($path, 'reference.docx');
                    $this->assertSame('valid', $kind);
                    $this->assertStringContainsString('Approved DOCX reference', $result['text']);
                    $this->assertSame('Paragraph 1', $result['segments'][0]['locator']);
                } catch (RuntimeException) { $this->assertNotSame('valid', $kind); }
            }
        } finally { if (is_file($path)) unlink($path); }
    }

    public function test_real_pdf_parser_preserves_page_locators_and_rejects_scanned_and_active_pdfs(): void
    {
        $directory = storage_path('framework/testing/source-parser');
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $path = $directory.'/reference.pdf';
        try {
            foreach (['text', 'scanned', 'active'] as $kind) {
                $stream = $kind === 'scanned' ? '' : 'BT /F1 12 Tf 50 740 Td (Approved reference text for the content brief.) Tj ET';
                $objects = ['<< /Type /Catalog /Pages 2 0 R '.($kind === 'active' ? '/OpenAction << /S /JavaScript /JS (unsafe) >>' : '').' >>',
                    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
                    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
                    '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream"];
                $pdf = "%PDF-1.4\n"; $offsets = [];
                foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n"; }
                $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
                foreach ($offsets as $offset) $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
                $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
                file_put_contents($path, $pdf);
                try {
                    $result = (new SourceExtractor)->file($path, 'reference.pdf');
                    $this->assertSame('text', $kind);
                    $this->assertStringContainsString('Approved reference text', $result['text']);
                    $this->assertSame(1, $result['segments'][0]['page']);
                } catch (RuntimeException $error) {
                    $this->assertNotSame('text', $kind, $error->getMessage());
                    $this->assertStringContainsString($kind === 'scanned' ? 'no readable text' : 'scripts', $error->getMessage());
                }
            }
        } finally { if (is_file($path)) unlink($path); }
    }
}
