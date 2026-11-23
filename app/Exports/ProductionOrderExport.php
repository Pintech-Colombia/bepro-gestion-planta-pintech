<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * La hoja de planta de la OP en Excel: la misma del PDF, para corregirla e imprimirla en una hoja carta vertical.
 */
class ProductionOrderExport implements FromView, WithColumnWidths, WithDrawings, WithEvents, WithTitle
{
    /**
     * La cuadrícula de la plantilla (resources/views/excel/production-order.blade.php): 12 columnas iguales que, con
     * Arial 10, suman el ancho imprimible de la carta (7,9 pulgadas con márgenes de 0,3): la hoja se imprime al 100 %.
     */
    private const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

    /**
     * @param  array<string, mixed>  $orderData
     */
    public function __construct(
        private readonly array $orderData,
        private readonly ?string $logoPath = null
    ) {}

    /**
     * @return array<string, float>
     */
    public function columnWidths(): array
    {
        return array_fill_keys(self::COLUMNS, 8.3);
    }

    public function title(): string
    {
        // Excel no admite más de 31 caracteres en el nombre de una hoja.
        return mb_substr((string) $this->orderData['order_number'], 0, 31);
    }

    public function drawings(): Drawing|array
    {
        $logoPath = $this->logoPath ?? public_path('images/logo-pintech.png');

        if (! file_exists($logoPath)) {
            return [];
        }

        $drawing = new Drawing;
        $drawing->setName('Pintech Logo');
        $drawing->setDescription('Logo Corporativo');
        $drawing->setPath($logoPath);
        $drawing->setHeight(56);
        $drawing->setCoordinates('A1');
        // Centrado en el recuadro del logo (A1:C3, unos 189 × 96 px).
        $drawing->setOffsetX(44);
        $drawing->setOffsetY(20);

        return $drawing;
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            // Antes de cargar la plantilla: cada celda que ella estiliza parte de esta fuente.
            BeforeSheet::class => function (BeforeSheet $event): void {
                $event->sheet->getDelegate()->getParent()?->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
            },
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();

                // La cabecera alta para que quepa el logo.
                foreach ([1, 2, 3] as $row) {
                    $sheet->getRowDimension($row)->setRowHeight(24);
                }

                // Las cantidades van como texto con coma decimal, igual que en el PDF: sin el aviso de «número
                // almacenado como texto» en cada una.
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    $sheet->getCell($coordinate)->getIgnoredErrors()->setNumberStoredAsText(true);
                }
                // «2.0» como texto: leído de la plantilla queda el número 2 (o «2,0» en un Excel en español).
                $sheet->setCellValueExplicit('K2', '2.0', DataType::TYPE_STRING);

                // Carta vertical, ajustada a una sola hoja, como el PDF.
                $sheet->getPageSetup()
                    ->setPaperSize(PageSetup::PAPERSIZE_LETTER)
                    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
                    ->setFitToPage(true)
                    ->setFitToWidth(1)
                    ->setFitToHeight(1)
                    ->setHorizontalCentered(true)
                    ->setPrintArea("A1:L{$lastRow}");
                $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);
                $sheet->setShowGridlines(false);
            },
        ];
    }

    public function view(): View
    {
        return view('excel.production-order', [
            'order' => $this->orderData,
        ]);
    }
}
