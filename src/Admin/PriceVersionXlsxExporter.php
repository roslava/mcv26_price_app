<?php

declare(strict_types=1);

namespace Mcv26\Price\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Mcv26\Price\Database\DatabasePriceRepository;
use Mcv26\Price\Exception\PriceVersionExportException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PriceVersionXlsxExporter
{
    private const UNDATED_FILENAME = 'mcv26_price_undated.xlsx';
    private const BASE_TITLE = 'Прайс ООО «Медицинский Центр Власова»';

    public function __construct(private readonly DatabasePriceRepository $repository)
    {
    }

    /** @return array{filename: string, title: string, price_date: string|null, categories: int, services: int} */
    public function export(int $versionId, string $destination): array
    {
        if ($versionId < 1) {
            throw PriceVersionExportException::invalidVersionId();
        }
        $version = $this->repository->loadVersion($versionId);
        if ($version === null) {
            throw PriceVersionExportException::notFound();
        }

        [$title, $filename, $priceDate] = $this->titleAndFilename($version['price_date'] ?? null);
        $spreadsheet = new Spreadsheet();
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Прайс');
            $sheet->mergeCells('A1:D1');
            $sheet->setCellValueExplicit('A1', $title, DataType::TYPE_STRING);
            $sheet->fromArray(['№ услуги', 'Код услуги', 'Наименование услуги', '₽'], null, 'A2');

            $row = 3;
            $serviceCount = 0;
            foreach ($version['categories'] as $category) {
                $sheet->mergeCells("A{$row}:D{$row}");
                $sheet->setCellValueExplicit("A{$row}", (string) $category['name'], DataType::TYPE_STRING);
                $sheet->getStyle("A{$row}:D{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCEBED']],
                ]);
                $row++;
                foreach ($category['services'] as $service) {
                    $sheet->setCellValueExplicit("A{$row}", (int) $service['service_number'], DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit("B{$row}", (string) $service['code'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit("C{$row}", (string) $service['name'], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit(
                        "D{$row}",
                        self::minorToDecimal((int) $service['current_price_minor']),
                        DataType::TYPE_NUMERIC
                    );
                    $serviceCount++;
                    $row++;
                }
            }
            if ($serviceCount === 0) {
                throw PriceVersionExportException::emptyVersion();
            }

            $lastRow = $row - 1;
            $sheet->getStyle('A1:D1')->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A1:D1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A2:D2')->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E9F1F3']],
            ]);
            $sheet->getStyle("A2:D{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('C7D5D9');
            $sheet->getStyle("C3:C{$lastRow}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("D3:D{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$₽-ru-RU]');
            $sheet->getColumnDimension('A')->setWidth(12);
            $sheet->getColumnDimension('B')->setWidth(22);
            $sheet->getColumnDimension('C')->setWidth(64);
            $sheet->getColumnDimension('D')->setWidth(16);
            $sheet->getRowDimension(1)->setRowHeight(24);
            $sheet->freezePane('A3');
            $sheet->setSelectedCell('A1');

            (new Xlsx($spreadsheet))->save($destination);
            return [
                'filename' => $filename,
                'title' => $title,
                'price_date' => $priceDate,
                'categories' => count($version['categories']),
                'services' => $serviceCount,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public static function minorToDecimal(int $minor): string
    {
        if ($minor < 1) {
            throw new PriceVersionExportException('Price must be positive.');
        }
        return intdiv($minor, 100) . '.' . sprintf('%02d', $minor % 100);
    }

    /** @return array{string, string, string|null} */
    private function titleAndFilename(mixed $savedDate): array
    {
        if (!is_string($savedDate) || $savedDate === '') {
            return [self::BASE_TITLE . ' — дата не указана', self::UNDATED_FILENAME, null];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $savedDate, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) {
            return [self::BASE_TITLE . ' — дата не указана', self::UNDATED_FILENAME, null];
        }
        $normalized = $date->format('Y-m-d');
        return [
            self::BASE_TITLE . ' от ' . $date->format('d. m. Y') . ' г.',
            'mcv26_price_' . $normalized . '.xlsx',
            $normalized,
        ];
    }
}
