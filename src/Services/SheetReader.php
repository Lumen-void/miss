<?php

declare(strict_types=1);

namespace MisTool\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

final class SheetReader
{
    public function sheets(string $path, ?array $loadSheetsOnly = null): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            return ['CSV' => $this->readCsv($path)];
        }
        if ($ext === 'txt') {
            return ['TXT' => $this->rowsFromText((string) file_get_contents($path))];
        }
        if ($ext === 'html' || $ext === 'htm') {
            $tables = $this->readHtmlTables($path);
            if ($tables) {
                return $tables;
            }
            return ['HTML' => $this->rowsFromText(strip_tags((string) file_get_contents($path)))];
        }
        if ($ext === 'pdf') {
            return ['PDF' => $this->rowsFromText($this->readPdfText($path))];
        }
        if ($ext === 'docx') {
            $tables = $this->readDocxTables($path);
            if ($tables) {
                return $tables;
            }
            return ['DOCX' => $this->rowsFromText($this->readDocxText($path))];
        }
        if ($ext === 'doc') {
            return ['DOC' => $this->rowsFromText($this->readDocWithTextutil($path))];
        }
        if ($ext === 'zip') {
            return $this->readZipSheets($path, $loadSheetsOnly);
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        if ($loadSheetsOnly) {
            $reader->setLoadSheetsOnly($loadSheetsOnly);
        }
        $workbook = $reader->load($path);
        $sheets = [];
        foreach ($workbook->getWorksheetIterator() as $sheet) {
            $sheets[$sheet->getTitle()] = $this->readWorksheet($sheet);
        }
        return $sheets;
    }

    public function tableFromRows(array $rows, array $required = []): array
    {
        $headerIndex = $this->findHeaderRow($rows, $required);
        if ($headerIndex === null) {
            throw new RuntimeException('Could not find required headers: ' . implode(', ', array_map(fn($value) => is_array($value) ? implode(' / ', $value) : (string) $value, $required)));
        }

        $headers = [];
        foreach ($rows[$headerIndex] as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);
            if ($normalized !== '') {
                $headers[$index] = $normalized;
            }
        }

        $records = [];
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $raw = $rows[$i];
            if (!$this->hasAnyValue($raw)) {
                continue;
            }
            $record = ['_row_number' => $i + 1];
            foreach ($headers as $index => $header) {
                $record[$header] = $raw[$index] ?? null;
            }
            $records[] = $record;
        }

        return $records;
    }

    public function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function readWorksheet(Worksheet $sheet): array
    {
        $rows = [];
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        for ($row = 1; $row <= $highestRow; $row++) {
            $values = [];
            foreach ($sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, null, false, false)[0] as $value) {
                $values[] = $value;
            }
            $rows[] = $values;
        }
        return $rows;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Could not open CSV file.');
        }
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function readPdfText(string $path): string
    {
        try {
            $text = (new PdfParser())->parseFile($path)->getText();
            if (trim($text) !== '') {
                return $text;
            }
            return $this->readPdfWithOcr($path);
        } catch (\Throwable $e) {
            if ($this->commandPath('tesseract') && $this->commandPath('pdftoppm')) {
                return $this->readPdfWithOcr($path);
            }
            throw new RuntimeException('Could not extract PDF text: ' . $e->getMessage());
        }
    }

    private function readPdfWithOcr(string $path): string
    {
        $tesseract = $this->commandPath('tesseract');
        $pdftoppm = $this->commandPath('pdftoppm');
        if (!$tesseract || !$pdftoppm) {
            throw new RuntimeException('PDF appears to be scanned. Install tesseract and poppler for OCR support.');
        }

        $tempDir = sys_get_temp_dir() . '/mis_ocr_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new RuntimeException('Could not create OCR temporary folder.');
        }

        try {
            $prefix = $tempDir . '/page';
            $cmd = escapeshellarg($pdftoppm) . ' -r 220 -png ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
            shell_exec($cmd);
            $text = '';
            foreach (glob($tempDir . '/page-*.png') ?: [] as $image) {
                $ocr = shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($image) . ' stdout -l eng --psm 6 2>/dev/null');
                if (is_string($ocr)) {
                    $text .= "\n" . $ocr;
                }
            }
            if (trim($text) === '') {
                throw new RuntimeException('OCR produced no readable text.');
            }
            return $text;
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }

    private function commandPath(string $command): string
    {
        foreach (['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'] as $dir) {
            $candidate = $dir . '/' . $command;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) ? trim($result) : '';
    }

    private function readHtmlTables(string $path): array
    {
        $html = (string) file_get_contents($path);
        if (trim($html) === '') {
            return [];
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML($html)) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $tables = [];
        $index = 1;
        foreach ($xpath->query('//table') ?: [] as $table) {
            $rows = [];
            foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
                $row = [];
                foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
                    $row[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? $cell->textContent);
                }
                if ($this->hasAnyValue($row)) {
                    $rows[] = $row;
                }
            }
            if ($rows) {
                $tables['HTML Table ' . $index] = $rows;
                $index++;
            }
        }
        return $tables;
    }

    private function readDocxTables(string $path): array
    {
        $xml = $this->docxDocumentXml($path);
        if ($xml === '') {
            return [];
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml)) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $tables = [];
        $tableIndex = 1;
        foreach ($xpath->query('//w:tbl') ?: [] as $table) {
            $rows = [];
            foreach ($xpath->query('.//w:tr', $table) ?: [] as $tr) {
                $row = [];
                foreach ($xpath->query('./w:tc', $tr) ?: [] as $tc) {
                    $parts = [];
                    foreach ($xpath->query('.//w:t', $tc) ?: [] as $textNode) {
                        $parts[] = $textNode->textContent;
                    }
                    $row[] = trim(implode(' ', $parts));
                }
                if ($this->hasAnyValue($row)) {
                    $rows[] = $row;
                }
            }
            if ($rows) {
                $tables['DOCX Table ' . $tableIndex] = $rows;
                $tableIndex++;
            }
        }
        return $tables;
    }

    private function readDocxText(string $path): string
    {
        $xml = $this->docxDocumentXml($path);
        if ($xml === '') {
            throw new RuntimeException('Could not read DOCX document.xml.');
        }
        $text = preg_replace('/<w:p\b[^>]*>/i', "\n", $xml) ?? $xml;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function docxDocumentXml(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open DOCX file.');
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        return $xml;
    }

    private function readDocWithTextutil(string $path): string
    {
        $cmd = '/usr/bin/textutil -convert txt -stdout ' . escapeshellarg($path) . ' 2>/dev/null';
        $text = shell_exec($cmd);
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Could not extract DOC text. Save this file as DOCX/XLSX/CSV if extraction fails.');
        }
        return $text;
    }

    private function readZipSheets(string $path, ?array $loadSheetsOnly): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Could not open ZIP file.');
        }

        $supported = ['csv', 'xlsx', 'xls', 'pdf', 'docx', 'doc', 'txt', 'html', 'htm'];
        $tempDir = sys_get_temp_dir() . '/mis_zip_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            $zip->close();
            throw new RuntimeException('Could not create temporary ZIP extraction folder.');
        }

        $sheets = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $supported, true)) {
                    continue;
                }
                $target = $tempDir . '/' . basename($name);
                $contents = $zip->getFromIndex($i);
                if ($contents === false) {
                    continue;
                }
                file_put_contents($target, $contents);
                foreach ($this->sheets($target, $loadSheetsOnly) as $sheetName => $rows) {
                    $sheets[basename($name) . ' - ' . $sheetName] = $rows;
                }
            }
        } finally {
            $zip->close();
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }

        if (!$sheets) {
            throw new RuntimeException('ZIP did not contain a supported sales document.');
        }
        return $sheets;
    }

    private function rowsFromText(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t]+/', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }
            $cells = preg_split('/\s{2,}|\t| {1,}(?=\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b)/u', $line) ?: [$line];
            if (count($cells) === 1) {
                $cells = preg_split('/\s{2,}/u', $line) ?: [$line];
            }
            $rows[] = array_map('trim', $cells);
        }
        if (!$rows) {
            throw new RuntimeException('Document text extraction produced no readable rows.');
        }
        return $rows;
    }

    private function findHeaderRow(array $rows, array $required): ?int
    {
        $requiredGroups = array_map(
            fn($header) => array_map(fn($candidate) => $this->normalizeHeader((string) $candidate), (array) $header),
            $required
        );
        foreach ($rows as $index => $row) {
            $headers = array_map(fn($header) => $this->normalizeHeader((string) $header), $row);
            $hits = 0;
            foreach ($requiredGroups as $requiredGroup) {
                if (array_intersect($requiredGroup, $headers)) {
                    $hits++;
                }
            }
            if ($hits >= max(1, min(count($requiredGroups), 3))) {
                return $index;
            }
        }
        return null;
    }

    private function hasAnyValue(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }
}
