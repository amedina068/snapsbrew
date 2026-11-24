<?php

namespace App\Http\Controllers;

use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Rules\SafeUrl;
use App\Rules\ValidLayers;
use App\Services\SnapBatchService;
use App\Support\VariantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SnapImportController extends Controller
{
    public function index($templateUuid)
    {
        $template = $this->resolveTemplate($templateUuid);

        $imports = $template->imports()
            ->latest('id')
            ->withCount([
                'snaps as completed_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_COMPLETED),
                'snaps as pending_snaps_count' => fn ($query) => $query->whereIn('status', [Snap::STATUS_PENDING, Snap::STATUS_PROCESSING]),
                'snaps as failed_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_FAILED),
                'snaps as canceled_snaps_count' => fn ($query) => $query->where('status', Snap::STATUS_CANCELED),
            ])
            ->paginate(10);

        return view('templates.snap-imports.index', [
            'template' => $template,
            'imports' => $imports,
        ]);
    }

    public function create($templateUuid)
    {
        $template = $this->resolveTemplate($templateUuid);

        return view('templates.snap-imports.create', [
            'template' => $template,
        ]);
    }

    public function show($templateUuid, $importUuid)
    {
        $template = $this->resolveTemplate($templateUuid);

        $import = $template->imports()
            ->uuid($importUuid)
            ->with(['snaps' => fn ($query) => $query->latest('id')])
            ->firstOrFail();

        $progress = $import->progressSnapshot();

        return view('templates.snap-imports.show', [
            'template' => $template,
            'import' => $import,
            'progress' => $progress,
        ]);
    }

    public function downloadTemplate($templateUuid)
    {
        $template = $this->resolveTemplate($templateUuid);

        $spreadsheet = $this->buildSpreadsheetForTemplate($template);

        $filename = Str::slug($template->title ?: 'template').'-import.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request, SnapBatchService $batches, $templateUuid)
    {
        $template = $this->resolveTemplate($templateUuid);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            // SafeUrl, not just `url`. The server POSTs to this address from the
            // render host, so an unguarded value turns that host into a proxy
            // into cloud metadata or an internal service. Same control the
            // caller-supplied `image_url` already carries in VariantRules.
            'webhook_url' => ['nullable', 'url', 'max:2048', new SafeUrl],
            // `mimes` reads the file content. A CSV has no signature, so libmagic
            // reports text/plain, which guesses as `txt`. `extensions` reads the
            // claimed name and blocks the real .txt file that `mimes` lets through.
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'extensions:xlsx,xls,csv', 'max:10240'],
        ], [
            'file.mimes' => 'The file content does not match xlsx, xls, or csv format. Upload a valid file in one of those formats.',
            'file.extensions' => 'The file name must end in .xlsx, .xls, or .csv. Rename the file or choose a different one.',
            'file.max' => 'The file is larger than 10 MB. Split the file into smaller files.',
        ]);

        $user = Auth::user();

        $layerDefinitions = $this->extractLayerDefinitions($template);

        $extension = strtolower(trim($request->file('file')->getClientOriginalExtension()));

        [$columnMap, $rows] = $this->readSpreadsheet($request->file('file')->getRealPath(), $extension);

        $headers = array_values($columnMap);

        if (! count($headers)) {
            throw ValidationException::withMessages([
                'file' => 'The file has no header row. Add a header row with the layer keys as column names.',
            ]);
        }

        $layerKeys = $layerDefinitions->keys();

        $unknownHeaders = collect($headers)->diff($layerKeys);

        if ($unknownHeaders->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'The file has unknown columns: '.implode(', ', $unknownHeaders->all()).'. Rename each column to match a layer key from the template.',
            ]);
        }

        $missingHeaders = $layerKeys->diff($headers);

        if ($missingHeaders->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => 'The file is missing columns: '.implode(', ', $missingHeaders->all()).'. Add a column for each layer key.',
            ]);
        }

        $dataRows = $this->mapRowsToLayers($columnMap, $rows);

        if (! count($dataRows)) {
            throw ValidationException::withMessages([
                'file' => 'The file has a header row but no data rows. Add at least one row below the header.',
            ]);
        }

        if (count($dataRows) > self::maxRows()) {
            throw $this->tooManyRows();
        }

        $variantRows = $this->validatedVariants($dataRows, $layerDefinitions);

        $import = $batches->create(
            $template,
            [
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'title' => $request->input('title'),
                'webhook_url' => $request->input('webhook_url'),
                'original_filename' => $request->file('file')->getClientOriginalName(),
            ],
            collect($dataRows)->map(fn ($row, $rowNumber) => [
                'variants' => $variantRows[$rowNumber],
                'metadata' => ['import_row' => $row],
                // This surface posts one `snap.completed` event per item plus the
                // terminal event. The screen at
                // `templates/snap-imports/create.blade.php` promises that.
                'webhook_url' => $request->input('webhook_url'),
            ])->values()->all(),
        );

        // After the batch starts, on purpose. Nothing reads `stored_path`, so a
        // storage failure must not discard a batch that is already rendering.
        $import->update([
            'stored_path' => $request->file('file')->storeAs(
                'snap-imports/'.$template->uuid,
                $import->uuid.'.'.$extension,
                'local'
            ),
        ]);

        return response()->json([
            'import' => $this->formatImportResponse($import->fresh()),
            'redirect_url' => url("templates/{$template->uuid}/imports/{$import->uuid}"),
            'status_url' => url("imports/{$import->uuid}/status"),
            'cancel_url' => url("imports/{$import->uuid}/cancel"),
        ], 201);
    }

    protected function resolveTemplate($templateUuid): Template
    {
        return Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();
    }

    /**
     * The batch renders the published version, so the columns and the cell
     * mapping read that version. An unpublished draft can have other keys.
     */
    protected function extractLayerDefinitions(Template $template): Collection
    {
        return collect(json_decode($template->renderableLayers(), true))
            ->mapWithKeys(function ($layer) {
                $properties = collect($layer['properties'] ?? []);

                $definition = [
                    'key' => $layer['key'],
                    'type' => $layer['type'] ?? 'text',
                    'modifier_key' => null,
                    'default' => null,
                ];

                if ($properties->firstWhere('name', 'textContent')) {
                    $definition['modifier_key'] = 'text';
                    $definition['default'] = $properties->firstWhere('name', 'textContent')['value'];
                } elseif ($properties->firstWhere('name', 'qrContent')) {
                    $definition['modifier_key'] = 'qr_content';
                    $definition['default'] = $properties->firstWhere('name', 'qrContent')['value'];
                } elseif ($properties->firstWhere('name', 'barcodeContent')) {
                    // Before the background branches: a barcode layer also has a background color.
                    $definition['modifier_key'] = 'barcode_content';
                    $definition['default'] = $properties->firstWhere('name', 'barcodeContent')['value'];
                } elseif ($properties->firstWhere('name', 'backgroundImage')) {
                    $definition['modifier_key'] = 'image_url';
                    $definition['default'] = $properties->firstWhere('name', 'backgroundImage')['value'];
                } elseif ($properties->firstWhere('name', 'backgroundColor')) {
                    $definition['modifier_key'] = 'background_color';
                    $definition['default'] = $properties->firstWhere('name', 'backgroundColor')['value'];
                } else {
                    $definition['default'] = optional($properties->first())['value'] ?? null;
                }

                return [$layer['key'] => $definition];
            });
    }

    protected function buildSpreadsheetForTemplate(Template $template): Spreadsheet
    {
        $layerDefinitions = $this->extractLayerDefinitions($template);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Write every cell as a string. Layer text that starts with `=` is
        // otherwise a formula, and Excel runs it when the customer opens the file.
        $column = 1;
        foreach ($layerDefinitions as $definition) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->setCellValueExplicit($letter.'1', (string) $definition['key'], DataType::TYPE_STRING);

            if ($definition['default'] !== null) {
                $sheet->setCellValueExplicit($letter.'2', (string) $definition['default'], DataType::TYPE_STRING);
            }

            $column++;
        }

        return $spreadsheet;
    }

    protected function readSpreadsheet(string $path, string $extension): array
    {
        try {
            // Read the size of every sheet before the file loads. The reader
            // streams the sheets for this count, and `load()` keeps every cell
            // of every sheet in memory.
            $this->assertSheetSize($this->reader($path, $extension), $path);

            $spreadsheet = $this->reader($path, $extension)->load($path);

            // toArray() must run inside the try block, because it parses more of
            // the file. A cell that starts with `=` is bound as a formula, even in
            // a CSV file. `calculateFormulas` is false, so toArray() returns the
            // text of the formula and never runs it.
            $rows = $spreadsheet->getActiveSheet()->toArray(null, false, true, true);
        } catch (SpreadsheetException) {
            throw ValidationException::withMessages([
                'file' => 'The app cannot read the file. Save the file again as xlsx, xls, or csv.',
            ]);
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }
        }

        // `toArray()` keys the rows by their row number in the file. Keep the
        // keys, so an error message can name the row that the customer sees.
        $headerRow = $rows === [] ? [] : $rows[array_key_first($rows)];
        unset($rows[array_key_first($rows)]);

        $columnMap = collect($headerRow)
            ->filter(fn ($value) => $value !== null && trim((string) $value) !== '')
            ->mapWithKeys(fn ($value, $column) => [$column => trim((string) $value)])
            ->all();

        return [$columnMap, $rows];
    }

    protected function reader(string $path, string $extension): BaseReader
    {
        $reader = $extension === 'csv' ? $this->csvReader() : IOFactory::createReaderForFile($path);

        if (! $reader instanceof BaseReader) {
            throw new SpreadsheetException('The file type has no sheet size check.');
        }

        return $reader;
    }

    /**
     * Refuse a file before it loads when one of its sheets holds more rows
     * than an import accepts, or more columns than a template has layers.
     */
    protected function assertSheetSize(BaseReader $reader, string $path): void
    {
        // An empty cell with only a style does not count as a row.
        $reader->setReadEmptyCells(false);

        foreach ($reader->listWorksheetInfo($path) as $sheet) {
            // One header row plus the data rows.
            if ($sheet['totalRows'] > self::maxRows() + 1) {
                throw $this->tooManyRows();
            }

            if ($sheet['totalColumns'] > ValidLayers::MAX_LAYERS) {
                throw ValidationException::withMessages([
                    'file' => 'The file has more than '.ValidLayers::MAX_LAYERS.' columns. Remove the columns that are not layer keys.',
                ]);
            }
        }
    }

    protected static function maxRows(): int
    {
        return (int) config('snapsbrew.import_max_rows');
    }

    protected function tooManyRows(): ValidationException
    {
        return ValidationException::withMessages([
            'file' => 'An import accepts at most '.self::maxRows().' rows. Split the file into smaller files.',
        ]);
    }

    /**
     * Build the variants of each row and check them with the rules of the
     * REST API. A cell is the same input as a `variants` value of
     * `POST /v1/snaps`, and the renderer reads it the same way.
     *
     * @param  array<int, array<string, mixed>>  $dataRows  keyed by the row number in the file
     * @return array<int, array<int, array<string, mixed>>> keyed by the row number in the file
     */
    protected function validatedVariants(array $dataRows, Collection $layerDefinitions): array
    {
        $rules = VariantRules::rules();
        $variantRows = [];

        // `SafeUrl` resolves the host of each image URL. A file often repeats
        // one URL on every row, so each URL is checked one time.
        $safeUrls = [];

        foreach ($dataRows as $rowNumber => $row) {
            $variants = $this->buildVariantsPayload($row, $layerDefinitions);
            $unchecked = $variants;

            foreach ($unchecked as $index => $variant) {
                if (isset($variant['image_url']) && isset($safeUrls[$variant['image_url']])) {
                    unset($unchecked[$index]['image_url']);
                }
            }

            $validator = Validator::make(['variants' => $unchecked], $rules);

            if ($validator->fails()) {
                $field = array_key_first($validator->errors()->messages());
                $index = (int) explode('.', $field)[1];

                throw ValidationException::withMessages([
                    'file' => "Row {$rowNumber}, column {$variants[$index]['layer']}: ".self::cellMessage($field),
                ]);
            }

            foreach ($variants as $variant) {
                if (isset($variant['image_url'])) {
                    $safeUrls[$variant['image_url']] = true;
                }
            }

            $variantRows[$rowNumber] = $variants;
        }

        return $variantRows;
    }

    private static function cellMessage(string $field): string
    {
        return match (substr($field, strrpos($field, '.') + 1)) {
            'image_url' => 'the value must be a public http or https URL. An internal or reserved address is not accepted.',
            'background_color' => 'the value must be a CSS color: hex, rgb(), rgba(), hsl(), hsla() or a color name.',
            'barcode_content' => 'the value must be 1000 characters or fewer.',
            default => 'the value is not valid for this layer.',
        };
    }

    protected function csvReader(): Csv
    {
        $reader = new Csv;

        // GUESS_ENCODING skips a byte order mark and falls back to CP1252, which
        // is what Excel writes on Windows. The reader infers `,` or `;` on its own.
        $reader->setInputEncoding(Csv::GUESS_ENCODING);

        return $reader;
    }

    protected function mapRowsToLayers(array $columnMap, array $rows): array
    {
        $mapped = [];

        foreach ($rows as $rowNumber => $row) {
            $rowValues = [];

            foreach ($columnMap as $column => $header) {
                $rowValues[$header] = $row[$column] ?? null;
            }

            if (collect($rowValues)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isEmpty()) {
                continue;
            }

            $mapped[$rowNumber] = $rowValues;
        }

        return $mapped;
    }

    protected function buildVariantsPayload(array $row, Collection $layerDefinitions): array
    {
        return collect($row)
            ->map(function ($value, $layerKey) use ($layerDefinitions) {
                $definition = $layerDefinitions->get($layerKey);

                if ($definition === null) {
                    return null;
                }

                $modifierKey = $definition['modifier_key'];

                if ($modifierKey === null) {
                    return null;
                }

                if ($value === null || trim((string) $value) === '') {
                    return null;
                }

                return array_merge([
                    'layer' => $layerKey,
                ], [
                    $modifierKey => $value,
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function formatImportResponse(SnapImport $import): array
    {
        return array_merge(
            $import->progressSnapshot(),
            [
                'title' => $import->title,
            ]
        );
    }
}
