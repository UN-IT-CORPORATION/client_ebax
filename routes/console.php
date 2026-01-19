<?php

use App\Models\Client;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Input\InputOption;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clients:import', function () {
    $filePath = public_path('excel.ods');

    if (!file_exists($filePath)) {
        $this->error("Le fichier {$filePath} est introuvable.");

        return self::FAILURE;
    }

    $this->info("Import des clients depuis : {$filePath}");

    // https://www.youtube.com/watch?v=EuzDbYXIAXI&list=RDzHpEeFXkhv0&index=27

    $allowedFields = [
        'nom_entreprise',
        'adresse_municipale',
        'ville',
        'code_postal',
        'telephone',
        'courriel',
    ];

    // Import en "chunks" pour éviter l'OOM (milliers de lignes)
    $reader = IOFactory::createReaderForFile($filePath);
    $reader->setReadDataOnly(true);

    // 1) Lire uniquement l'en-tête (ligne 1)
    $reader->setReadFilter(new RowRangeReadFilter(1, 1));
    $headerSpreadsheet = $reader->load($filePath);
    $headerSheet = $headerSpreadsheet->getActiveSheet();
    $columns = getImportColumnMap($headerSheet, $allowedFields);
    $headerSpreadsheet->disconnectWorksheets();
    unset($headerSpreadsheet, $headerSheet);

    if (empty($columns)) {
        $this->error("Aucune colonne reconnue dans l'en-tête. Colonnes attendues: " . implode(', ', $allowedFields));

        return self::FAILURE;
    }

    $chunkSize = (int) ($this->option('chunk') ?? 1000);
    $batchSize = (int) ($this->option('batch') ?? 500);

    $imported = 0;
    $now = now();

    // Désactive les logs de requêtes (réduit la RAM)
    DB::disableQueryLog();

    // On ne peut pas connaître "la dernière ligne" sans lire, donc on s'arrête
    // après plusieurs chunks consécutifs vides.
    $emptyChunksInARow = 0;
    $maxEmptyChunks = 3;

    for ($startRow = 2; $emptyChunksInARow < $maxEmptyChunks; $startRow += $chunkSize) {
        $endRow = $startRow + $chunkSize - 1;

        $reader->setReadFilter(new RowRangeReadFilter($startRow, $endRow));
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $batch = [];
        $chunkHasData = false;

        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $data = [];

            foreach ($columns as $columnLetter => $fieldName) {
                $value = $sheet->getCell($columnLetter . $rowIndex)->getValue();
                $data[$fieldName] = is_string($value) ? trim($value) : $value;
            }

            // Si la ligne est complètement vide, on l'ignore
            if (!array_filter($data)) {
                continue;
            }

            $chunkHasData = true;

            // Insert en batch: il faut timestamps
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $batch[] = $data;

            if (count($batch) >= $batchSize) {
                Client::query()->insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Client::query()->insert($batch);
            $imported += count($batch);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);

        if (!$chunkHasData) {
            $emptyChunksInARow++;
        } else {
            $emptyChunksInARow = 0;
        }

        $this->line("Progress: {$imported} lignes importées...");
    }

    if ($imported === 0) {
        $this->warn("Aucune ligne importée. Vérifie que l'onglet actif contient des données et que la ligne 1 a les bons en-têtes.");
    }

    $this->info("{$imported} clients importés avec succès.");

    return self::SUCCESS;
})
    ->purpose('Importer les clients depuis le fichier public/excel.ods')
    ->addOption('chunk', null, InputOption::VALUE_OPTIONAL, 'Taille du chunk (lignes lues à la fois), ex: 1000', 1000)
    ->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Taille du batch SQL (insert), ex: 500', 500);

/**
 * Retourne un mapping de colonnes, ex: ['A' => 'nom_entreprise', 'B' => 'adresse_municipale', ...]
 *
 * @return array<string,string>
 */
function getImportColumnMap(Worksheet $sheet, array $allowedFields): array
{
    $highestColumn = $sheet->getHighestDataColumn(1);
    $headerRange = "A1:{$highestColumn}1";
    $headerRow = $sheet->rangeToArray($headerRange, null, true, true, true)[1] ?? [];

    $columns = [];
    foreach ($headerRow as $columnLetter => $columnName) {
        if (!is_string($columnName)) {
            continue;
        }

        $normalized = trim($columnName);
        if ($normalized === '') {
            continue;
        }

        if (in_array($normalized, $allowedFields, true)) {
            $columns[$columnLetter] = $normalized;
        }
    }

    return $columns;
}

class RowRangeReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
