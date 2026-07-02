<?php

namespace App\Services;

use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;

class CsvService
{
    /**
     * Export data to CSV with UTF-8 BOM.
     *
     * @param array $headers  Column header labels
     * @param iterable $rows  Data rows (array of arrays)
     * @param string $filename
     */
    public static function export(array $headers, iterable $rows, string $filename = 'export.csv')
    {
        $callback = function () use ($headers, $rows) {
            $file = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fwrite($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, $headers);
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Parse imported CSV file into array of rows (skips header row).
     *
     * @param Request $request
     * @param string $fieldName
     * @return array [headers, rows]
     */
    public static function parse(Request $request, string $fieldName = 'file'): array
    {
        $request->validate([
            $fieldName => 'required|file|mimes:csv,txt,xlsx,xls'
        ]);

        $path = $request->file($fieldName)->getRealPath();
        $data = array_map('str_getcsv', file($path));

        // Remove BOM if present
        if (!empty($data[0][0])) {
            $data[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0][0]);
        }

        $headers = array_shift($data);

        // Filter empty rows
        $rows = array_filter($data, fn($row) => count($row) > 1 || (count($row) === 1 && trim($row[0]) !== ''));

        return [$headers, array_values($rows)];
    }
}
