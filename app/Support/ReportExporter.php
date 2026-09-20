<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dependency-free report export.
 *
 * Excel : SpreadsheetML 2003 (.xls). Excel, LibreOffice and Google Sheets all
 *         open it natively, and it needs no extension or library. It is XML,
 *         so it also round-trips through most tooling.
 * PDF   : A print-ready HTML document served inline, which the user prints /
 *         "Save as PDF" from. This avoids pulling in a 10MB PDF engine and
 *         keeps fonts and layout identical to the on-screen report.
 *
 * Both honour the globally configured currency and are audited as exports.
 */
class ReportExporter
{
    /**
     * @param  string  $filename  base name without extension
     * @param  string  $title  report title shown in the sheet header
     * @param  array  $columns  [key => Label]
     * @param  iterable  $rows  rows as arrays keyed by the column keys
     * @param  array  $meta  extra key/value pairs shown above the table
     * @param  callable|null  $formatter  fn($value, $key) => string, for money etc.
     */
    public function __construct(
        protected string $filename,
        protected string $title,
        protected array $columns,
        protected iterable $rows,
        protected array $meta = [],
        protected ?\Closure $formatter = null,
    ) {
    }

    /* ------------------------------------------------------------------ *
     * Excel (SpreadsheetML)
     * ------------------------------------------------------------------ */

    public function excel(): StreamedResponse
    {
        $columns = $this->columns;
        $rows = is_array($this->rows) ? $this->rows : iterator_to_array($this->rows);
        $meta = $this->meta;
        $title = $this->title;
        $formatter = $this->formatter;
        $filename = $this->filename . '.xls';

        return response()->streamDownload(function () use ($columns, $rows, $meta, $title, $formatter) {
            echo $this->buildSpreadsheetXml($title, $columns, $rows, $meta, $formatter);
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    protected function buildSpreadsheetXml(string $title, array $columns, array $rows, array $meta, ?\Closure $formatter): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $out .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
            . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
        . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

        // Styles: title, meta label, header, plain, money.
        $out .= '<Styles>';
        $out .= '<Style ss:ID="title"><Font ss:Bold="1" ss:Size="14"/></Style>';
        $out .= '<Style ss:ID="meta"><Font ss:Italic="1" ss:Color="#555"/></Style>';
        $out .= '<Style ss:ID="head"><Font ss:Bold="1" ss:Color="#FFFFFF"/>'
            . '<Interior ss:Color="#4F46E5" ss:Pattern="Solid"/></Style>';
        $out .= '<Style ss:ID="cell"><Borders>'
            . '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#DD"/>'
            . '</Borders></Style>';
        $out .= '</Styles>';

        $out .= '<Worksheet ss:Name="Report"><Table>' . "\n";

        // Title row
        $out .= '<Row><Cell ss:StyleID="title"><Data ss:Type="String">' . $esc($title) . '</Data></Cell></Row>';
        $out .= '<Row></Row>';

        // Meta rows
        foreach ($meta as $label => $value) {
            $out .= '<Row>'
                . '<Cell ss:StyleID="meta"><Data ss:Type="String">' . $esc($label) . '</Data></Cell>'
                . '<Cell ss:StyleID="meta"><Data ss:Type="String">' . $esc($value) . '</Data></Cell>'
                . '</Row>';
        }
        if ($meta) {
            $out .= '<Row></Row>';
        }

        // Header
        $out .= '<Row>';
        foreach ($columns as $label) {
            $out .= '<Cell ss:StyleID="head"><Data ss:Type="String">' . $esc($label) . '</Data></Cell>';
        }
        $out .= '</Row>' . "\n";

        // Body
        foreach ($rows as $row) {
            $out .= '<Row>';
            foreach ($columns as $key => $label) {
                $raw = data_get($row, $key);
                $value = $formatter ? $formatter($raw, $key, $row) : $raw;

                // Keep truly numeric, unformatted values numeric so Excel can
                // still sum them; anything the formatter touched is a string
                // (it may carry a currency symbol).
                $isNumeric = ! $formatter && is_numeric($value);

                $type = $isNumeric ? 'Number' : 'String';
                $out .= '<Cell ss:StyleID="cell"><Data ss:Type="' . $type . '">'
                    . $esc($value) . '</Data></Cell>';
            }
            $out .= '</Row>' . "\n";
        }

        $out .= '</Table></Worksheet></Workbook>';

        return $out;
    }

    /* ------------------------------------------------------------------ *
     * PDF (print-ready HTML)
     * ------------------------------------------------------------------ */

    public function pdf(): Response
    {
        $columns = $this->columns;
        $rows = is_array($this->rows) ? $this->rows : iterator_to_array($this->rows);
        $html = view('exports.report-print', [
            'title' => $this->title,
            'columns' => $columns,
            'rows' => $rows,
            'meta' => $this->meta,
            'formatter' => $this->formatter,
            'generatedAt' => now()->format('j M Y, g:i A'),
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
