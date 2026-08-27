<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class TabularFileParser
{
    /** @return \Generator<int, array<string, string>> */
    public function rows(string $disk, string $path, string $extension): \Generator
    {
        $extension = strtolower($extension);
        if ($extension === 'csv' || $extension === 'txt') {
            yield from $this->csvRows(Storage::disk($disk)->readStream($path));

            return;
        }

        if ($extension !== 'xlsx') {
            throw new RuntimeException('Only CSV and XLSX files are supported.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'crm-xlsx-');
        file_put_contents($temporary, Storage::disk($disk)->get($path));
        try {
            yield from $this->xlsxRows($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /** @param resource|false $stream */
    private function csvRows($stream): \Generator
    {
        if ($stream === false) {
            throw new RuntimeException('The import file could not be opened.');
        }

        $headers = fgetcsv($stream);
        if ($headers === false) {
            fclose($stream);

            return;
        }
        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        while (($row = fgetcsv($stream)) !== false) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $values = array_pad($row, count($headers), null);
            yield array_combine($headers, array_slice($values, 0, count($headers))) ?: [];
        }
        fclose($stream);
    }

    /** @return \Generator<int, array<string, string>> */
    private function xlsxRows(string $file): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            throw new RuntimeException('The XLSX file is invalid.');
        }

        $sharedStrings = [];
        if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $shared = new SimpleXMLElement($sharedXml);
            foreach ($shared->si as $item) {
                $sharedStrings[] = implode('', array_map('strval', iterator_to_array($item->xpath('.//t') ?: [])));
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('The XLSX workbook has no first sheet.');
        }
        $sheet = new SimpleXMLElement($sheetXml);
        $rows = [];
        foreach ($sheet->sheetData->row as $xmlRow) {
            $values = [];
            foreach ($xmlRow->c as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/([A-Z]+)/', $reference, $matches);
                $column = $this->columnNumber($matches[1] ?? 'A');
                $value = (string) ($cell->v ?? '');
                if ((string) $cell['t'] === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }
                $values[$column] = $value;
            }
            $rows[] = $values;
        }
        $zip->close();

        $headers = array_map(fn ($value) => trim((string) $value), array_values($rows[0] ?? []));
        foreach (array_slice($rows, 1) as $row) {
            $row = array_values($row);
            $row = array_pad($row, count($headers), null);
            yield array_combine($headers, array_slice($row, 0, count($headers))) ?: [];
        }
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return max(1, $number) - 1;
    }
}
