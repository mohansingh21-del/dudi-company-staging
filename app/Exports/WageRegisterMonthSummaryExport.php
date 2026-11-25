<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * One month's register summarised on a single page — the "Export Summary"
 * download from the month detail screen. No employee rows; those are the
 * separate rows export.
 *
 * Written as banded label/value sections rather than a table, because that is
 * what a summary is: the stat tiles, who filed the register and when, the
 * earnings and deduction totals broken out by column, and the wage rates the
 * month was priced against.
 *
 * The section content is decided by the caller, so this class only knows how to
 * lay sections out. Each row is [label, value, format] where format is one of
 * 'money', 'number' or 'text'.
 */
class WageRegisterMonthSummaryExport implements FromArray, WithEvents, WithTitle, WithColumnWidths, WithStrictNullComparison
{
    /** Spreadsheet rows the title block occupies before the first section. */
    protected const HEADER_ROWS = 2;

    /** @var array<int,array{title:string,rows:array<int,array>}> */
    protected array $sections;

    protected string $label;

    public function __construct(array $sections, string $label)
    {
        $this->sections = $sections;
        $this->label = $label;
    }

    public function title(): string
    {
        return substr($this->label . ' Summary', 0, 31);
    }

    public function array(): array
    {
        // Rows 1-2 are written by the AfterSheet hook so the title can be merged
        // and styled. The placeholders must be full-width rows of empty strings,
        // not empty arrays: fromArray() does not advance the row pointer for an
        // empty array, which would let the title overwrite the first section.
        $rows = [['', ''], ['', '']];

        foreach ($this->sections as $section) {
            $rows[] = [$section['title'], ''];

            foreach ($section['rows'] as $row) {
                $rows[] = [
                    $row[0],
                    $row[1] === null ? '-' : $row[1],
                ];
            }

            // A spacer between sections, so the bands read as separate blocks.
            $rows[] = ['', ''];
        }

        // The trailing spacer is not wanted.
        array_pop($rows);

        return $rows;
    }

    public function columnWidths(): array
    {
        return ['A' => 38, 'B' => 24];
    }

    /**
     * Which spreadsheet row each section heading and each value lands on, so the
     * styling hook can find them without re-walking the layout logic.
     *
     * @return array{headings:array<int,int>,money:array<int,int>,numbers:array<int,int>,blocks:array<int,array{int,int}>}
     */
    protected function rowMap(): array
    {
        $row = self::HEADER_ROWS + 1;

        $map = ['headings' => [], 'money' => [], 'numbers' => [], 'blocks' => []];

        foreach ($this->sections as $section) {
            $start = $row;
            $map['headings'][] = $row++;

            foreach ($section['rows'] as $line) {
                $format = $line[2] ?? 'text';

                if ($format === 'money') {
                    $map['money'][] = $row;
                } elseif ($format === 'number') {
                    $map['numbers'][] = $row;
                }

                $row++;
            }

            // The heading through the section's last value — the spacer that
            // follows is left outside so the bands read as separate blocks
            // rather than one continuous grid.
            $map['blocks'][] = [$start, $row - 1];

            $row++; // spacer
        }

        return $map;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $map = $this->rowMap();
                $lastRow = $sheet->getHighestRow();

                // ── Title ──
                $sheet->setCellValue('A1', $this->label . ' — Wage Register Summary');
                $sheet->mergeCells('A1:B1');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A1')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // ── Section headings ──
                foreach ($map['headings'] as $row) {
                    $sheet->mergeCells('A' . $row . ':B' . $row);

                    $style = $sheet->getStyle('A' . $row . ':B' . $row);
                    $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                    $style->getFill()->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FF31859C');

                    $sheet->getRowDimension($row)->setRowHeight(20);
                }

                // ── Value formats ──
                // A '-' is text and the format leaves it alone, so a figure with
                // no source still reads as blank rather than 0.00.
                // Figures are right-aligned; labels and free text are left as
                // they are, since a right-aligned name reads badly.
                foreach ($map['money'] as $row) {
                    $sheet->getStyle('B' . $row)
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                    $sheet->getStyle('B' . $row)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                foreach ($map['numbers'] as $row) {
                    $sheet->getStyle('B' . $row)
                        ->getNumberFormat()->setFormatCode('#,##0');
                    $sheet->getStyle('B' . $row)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                // ── Grid, per section, so the spacer rows stay blank ──
                foreach ($map['blocks'] as [$start, $end]) {
                    $sheet->getStyle('A' . $start . ':B' . $end)
                        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                }

                $sheet->getStyle('A3:B' . $lastRow)->getAlignment()->setWrapText(true);
            },
        ];
    }
}
