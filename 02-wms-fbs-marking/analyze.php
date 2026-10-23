<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const OUTPUT_DIR = __DIR__ . '/output';

/**
 * @return array<int, array<string, string>>
 */
function readCsvFile(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть файл: {$path}");
    }

    $header = fgetcsv($handle, 0, ';');
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException("Пустой CSV-файл: {$path}");
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    if (count($header) !== count(array_unique($header))) {
        fclose($handle);
        throw new RuntimeException("CSV-файл содержит повторяющиеся колонки: {$path}");
    }
    $rows = [];

    while (($values = fgetcsv($handle, 0, ';')) !== false) {
        if ($values === [null]) {
            continue;
        }
        if (count($values) !== count($header)) {
            fclose($handle);
            throw new RuntimeException("Некорректное число колонок в {$path}");
        }
        $rows[] = array_combine($header, $values);
    }

    fclose($handle);

    return $rows;
}

/**
 * @param array<int, string> $header
 * @param array<int, array<int, scalar|null>> $rows
 */
function writeCsvFile(string $path, array $header, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось создать файл: {$path}");
    }

    try {
        // BOM нужен, чтобы Excel корректно распознавал UTF-8.
        if (fwrite($handle, "\xEF\xBB\xBF") === false || fputcsv($handle, $header, ';') === false) {
            throw new RuntimeException("Не удалось записать заголовок CSV: {$path}");
        }
        foreach ($rows as $row) {
            if (fputcsv($handle, $row, ';') === false) {
                throw new RuntimeException("Не удалось записать строку CSV: {$path}");
            }
        }
    } finally {
        fclose($handle);
    }
}

/**
 * @param array<string, array<int, array<string, string>>> $issues
 */
function addIssue(
    array &$issues,
    string $taskId,
    string $severity,
    string $code,
    string $message
): void {
    if (!isset($issues[$taskId])) {
        $issues[$taskId] = [];
    }
    $issues[$taskId][] = [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
    ];
}

/**
 * @param array<string, array<string, string>> $catalogBySku
 * @param array<string, array<string, string>> $catalogByBarcode
 * @param array<string, array<string, int>> $stockBySku
 * @param array<int, array<string, mixed>> $tasks
 * @return array{0: array<int, array<int, scalar>>, 1: array<int, array<int, scalar>>}
 */
function buildPickingList(
    array $catalogBySku,
    array $catalogByBarcode,
    array $stockBySku,
    array $tasks
): array {
    usort($tasks, static function (array $left, array $right): int {
        $leftTimestamp = (new DateTimeImmutable((string) $left['ship_by']))->getTimestamp();
        $rightTimestamp = (new DateTimeImmutable((string) $right['ship_by']))->getTimestamp();
        $byDate = $leftTimestamp <=> $rightTimestamp;
        return $byDate !== 0
            ? $byDate
            : strnatcmp((string) $left['task_id'], (string) $right['task_id']);
    });

    $remaining = $stockBySku;
    $picks = [];
    $unfulfillable = [];

    foreach ($tasks as $task) {
        $requiredBySku = [];
        $barcodeBySku = [];
        foreach ($task['items'] as $item) {
            $sku = (string) $item['sku'];
            $barcode = (string) $item['barcode'];
            if (!isset($catalogBySku[$sku])) {
                throw new RuntimeException("Артикул {$sku} из задания отсутствует в каталоге");
            }
            if (!isset($catalogByBarcode[$barcode]) || $catalogByBarcode[$barcode]['sku'] !== $sku) {
                throw new RuntimeException("Штрихкод {$barcode} не соответствует артикулу {$sku}");
            }
            if (isset($barcodeBySku[$sku]) && $barcodeBySku[$sku] !== $barcode) {
                throw new RuntimeException("Для артикула {$sku} в задании указаны разные штрихкоды");
            }
            $barcodeBySku[$sku] = $barcode;
            $requiredBySku[$sku] = ($requiredBySku[$sku] ?? 0) + (int) $item['qty'];
        }

        $shortages = [];
        foreach ($requiredBySku as $sku => $required) {
            $available = array_sum($remaining[$sku] ?? []);

            if ($available < $required) {
                $shortages[] = [
                    $sku,
                    $required,
                    $available,
                    $required - $available,
                ];
            }
        }

        // Не резервируем отдельные позиции, если целиком собрать задание нельзя.
        if ($shortages !== []) {
            foreach ($shortages as $shortage) {
                [$sku, $required, $available, $missing] = $shortage;
                $unfulfillable[] = [
                    $task['task_id'],
                    $task['marketplace'],
                    $task['ship_by'],
                    $sku,
                    $catalogBySku[$sku]['name'] ?? 'Неизвестный товар',
                    $required,
                    $available,
                    $missing,
                ];
            }
            continue;
        }

        foreach ($requiredBySku as $sku => $required) {
            $barcode = $barcodeBySku[$sku];
            $cells = array_keys($remaining[$sku] ?? []);
            usort($cells, 'strnatcmp');

            foreach ($cells as $cell) {
                if ($required === 0) {
                    break;
                }
                $quantity = min($required, $remaining[$sku][$cell]);
                if ($quantity === 0) {
                    continue;
                }

                $picks[] = [
                    $cell,
                    $task['task_id'],
                    $task['marketplace'],
                    $task['ship_by'],
                    $sku,
                    $catalogBySku[$sku]['name'],
                    $barcode,
                    $quantity,
                ];
                $remaining[$sku][$cell] -= $quantity;
                $required -= $quantity;
            }
        }
    }

    // Один проход по складу; внутри ячейки сначала более срочные задания.
    usort($picks, static function (array $left, array $right): int {
        $byCell = strnatcmp((string) $left[0], (string) $right[0]);
        if ($byCell !== 0) {
            return $byCell;
        }
        $leftTimestamp = (new DateTimeImmutable((string) $left[3]))->getTimestamp();
        $rightTimestamp = (new DateTimeImmutable((string) $right[3]))->getTimestamp();
        $byDate = $leftTimestamp <=> $rightTimestamp;
        return $byDate !== 0 ? $byDate : strnatcmp((string) $left[1], (string) $right[1]);
    });

    return [$picks, $unfulfillable];
}

/**
 * @param array<string, array<string, string>> $catalogByBarcode
 * @param array<int, array<string, mixed>> $tasks
 * @param array<int, array<string, string>> $scans
 * @return array<int, array<int, scalar>>
 */
function checkAssembly(array $catalogByBarcode, array $tasks, array $scans): array
{
    $tasksById = [];
    $expected = [];
    $issues = [];
    foreach ($tasks as $task) {
        $taskId = (string) $task['task_id'];
        if (isset($tasksById[$taskId])) {
            throw new RuntimeException("Дублирующееся задание {$taskId}");
        }
        $tasksById[$taskId] = $task;
        $issues[$taskId] = [];
        $expected[$taskId] = [];
        foreach ($task['items'] as $item) {
            $barcode = (string) $item['barcode'];
            $expected[$taskId][$barcode] =
                ($expected[$taskId][$barcode] ?? 0) + (int) $item['qty'];
        }
    }

    $actual = [];
    $markUses = [];
    foreach ($scans as $offset => $scan) {
        $line = $offset + 2;
        $taskId = trim($scan['task_id']);
        $barcode = trim($scan['barcode']);
        $mark = trim($scan['marking_code']);
        if ($mark !== '') {
            $markUses[$mark][] = ['task_id' => $taskId, 'line' => $line];
        }

        if (!isset($tasksById[$taskId])) {
            addIssue(
                $issues,
                $taskId,
                'ERROR',
                'UNKNOWN_TASK',
                "Строка {$line}: скан относится к неизвестному заданию"
            );
        }

        $actual[$taskId][$barcode] = ($actual[$taskId][$barcode] ?? 0) + 1;
        $product = $catalogByBarcode[$barcode] ?? null;
        if ($product === null) {
            addIssue(
                $issues,
                $taskId,
                'ERROR',
                'UNKNOWN_BARCODE',
                "Строка {$line}: штрихкод {$barcode} отсутствует в каталоге"
            );
            continue;
        }

        if ($product['marked'] === '1') {
            if ($mark === '') {
                addIssue(
                    $issues,
                    $taskId,
                    'ERROR',
                    'MARK_REQUIRED',
                    "Строка {$line}: для {$product['sku']} «{$product['name']}» не отсканирован КИЗ"
                );
            } else {
                $prefix = '01' . '0' . $barcode . '21';
                if (strpos($mark, $prefix) !== 0 || strlen($mark) === strlen($prefix)) {
                    addIssue(
                        $issues,
                        $taskId,
                        'ERROR',
                        'INVALID_MARK',
                        "Строка {$line}: КИЗ не соответствует товару {$product['sku']} или не содержит серийный номер"
                    );
                }
            }
        } elseif ($mark !== '') {
            addIssue(
                $issues,
                $taskId,
                'WARNING',
                'UNEXPECTED_MARK',
                "Строка {$line}: у немаркированного товара {$product['sku']} передан КИЗ"
            );
        }
    }

    foreach ($markUses as $mark => $uses) {
        if (count($uses) < 2) {
            continue;
        }
        $locations = array_map(
            static fn (array $use): string => "{$use['task_id']} (строка {$use['line']})",
            $uses
        );
        foreach (array_unique(array_column($uses, 'task_id')) as $taskId) {
            addIssue(
                $issues,
                (string) $taskId,
                'ERROR',
                'DUPLICATE_MARK',
                "КИЗ {$mark} использован повторно: " . implode(', ', $locations)
            );
        }
    }

    foreach (array_keys($tasksById) as $taskId) {
        foreach ($expected[$taskId] as $barcode => $required) {
            $scanned = $actual[$taskId][$barcode] ?? 0;
            $product = $catalogByBarcode[$barcode];
            if ($scanned < $required) {
                addIssue(
                    $issues,
                    $taskId,
                    'ERROR',
                    'ITEM_MISSING',
                    "Не хватает {$product['sku']} «{$product['name']}»: нужно {$required}, отсканировано {$scanned}"
                );
            } elseif ($scanned > $required) {
                addIssue(
                    $issues,
                    $taskId,
                    'ERROR',
                    'ITEM_EXCESS',
                    "Лишний {$product['sku']} «{$product['name']}»: нужно {$required}, отсканировано {$scanned}"
                );
            }
        }

        foreach ($actual[$taskId] ?? [] as $barcode => $scanned) {
            if (isset($expected[$taskId][$barcode])) {
                continue;
            }
            $product = $catalogByBarcode[$barcode] ?? null;
            if ($product === null) {
                continue;
            }
            addIssue(
                $issues,
                $taskId,
                'ERROR',
                'UNEXPECTED_ITEM',
                "Товар не входит в задание: {$product['sku']} «{$product['name']}», отсканировано {$scanned}"
            );
        }
    }

    $rows = [];
    foreach ($issues as $taskId => $taskIssues) {
        if ($taskIssues === []) {
            $rows[] = [$taskId, 'OK', 'INFO', 'OK', 'Задание собрано корректно'];
            continue;
        }
        foreach ($taskIssues as $issue) {
            $rows[] = [
                $taskId,
                'PROBLEM',
                $issue['severity'],
                $issue['code'],
                $issue['message'],
            ];
        }
    }

    return $rows;
}

/**
 * @param array<string, array<string, string>> $catalogBySku
 * @param array<string, array<string, string>> $catalogByBarcode
 * @param array<int, array<string, string>> $stockRows
 * @param array<int, array<string, string>> $countRows
 * @return array<int, array<int, scalar|null>>
 */
function compareInventory(
    array $catalogBySku,
    array $catalogByBarcode,
    array $stockRows,
    array $countRows
): array {
    $book = [];
    $bookLocationsBySku = [];
    $bookTotalBySku = [];
    foreach ($stockRows as $row) {
        $sku = $row['sku'];
        if (!isset($catalogBySku[$sku])) {
            throw new RuntimeException("Артикул {$sku} из остатков отсутствует в каталоге");
        }
        $barcode = $catalogBySku[$sku]['barcode'];
        $key = $row['cell'] . "\0" . $barcode;
        $book[$key] = ($book[$key] ?? 0) + (int) $row['qty'];
        $bookLocationsBySku[$sku][$row['cell']] = true;
        $bookTotalBySku[$sku] = ($bookTotalBySku[$sku] ?? 0) + (int) $row['qty'];
    }

    $counted = [];
    $countedCells = [];
    $countedTotalBySku = [];
    foreach ($countRows as $row) {
        $countedCells[$row['cell']] = true;
        $key = $row['cell'] . "\0" . $row['barcode'];
        $counted[$key] = ($counted[$key] ?? 0) + (int) $row['counted_qty'];
        if (isset($catalogByBarcode[$row['barcode']])) {
            $sku = $catalogByBarcode[$row['barcode']]['sku'];
            $countedTotalBySku[$sku] = ($countedTotalBySku[$sku] ?? 0) + (int) $row['counted_qty'];
        }
    }

    $allKeys = array_unique(array_merge(array_keys($book), array_keys($counted)));
    usort($allKeys, static function (string $left, string $right): int {
        [$leftCell, $leftBarcode] = explode("\0", $left);
        [$rightCell, $rightBarcode] = explode("\0", $right);
        $byCell = strnatcmp($leftCell, $rightCell);
        return $byCell !== 0 ? $byCell : strcmp($leftBarcode, $rightBarcode);
    });

    $rows = [];
    foreach ($allKeys as $key) {
        [$cell, $barcode] = explode("\0", $key);
        $hasBook = array_key_exists($key, $book);
        $hasCount = array_key_exists($key, $counted);
        $product = $catalogByBarcode[$barcode] ?? null;
        $bookQty = $book[$key] ?? 0;

        if (!$hasCount && $bookQty === 0) {
            continue;
        }
        if (!$hasCount && !isset($countedCells[$cell])) {
            $rows[] = [
                $cell,
                $product['sku'] ?? '',
                $barcode,
                $product['name'] ?? 'Неизвестный товар',
                $bookQty,
                '',
                '',
                'NOT_COUNTED',
                'Нет результата пересчёта: требуется пересчитать ячейку, недостача не подтверждена',
            ];
            continue;
        }

        $countedQty = $counted[$key] ?? 0;
        $delta = $countedQty - $bookQty;
        if ($delta === 0) {
            continue;
        }

        if ($product === null) {
            $classification = 'UNKNOWN_PRODUCT';
            $comment = 'Неизвестный штрихкод: идентифицировать товар до оприходования';
        } else {
            $sku = $product['sku'];
            $allBookLocationsCounted = true;
            foreach (array_keys($bookLocationsBySku[$sku] ?? []) as $bookCell) {
                if (!isset($countedCells[$bookCell])) {
                    $allBookLocationsCounted = false;
                    break;
                }
            }

            $totalBalanced = $allBookLocationsCounted
                && ($countedTotalBySku[$sku] ?? 0) === ($bookTotalBySku[$sku] ?? 0);

            if ($totalBalanced) {
                $classification = 'WRONG_CELL';
                $comment = $delta < 0
                    ? 'Товар отсутствует в учётной ячейке, но найден в другой; общий остаток совпадает'
                    : 'Товар найден не в своей ячейке; общий остаток совпадает';
            } elseif (!$hasBook) {
                $classification = 'UNEXPECTED_LOCATION';
                $comment = 'Товар найден в ячейке, где по учёту его нет; проверить перемещение';
            } elseif ($delta < 0) {
                $classification = 'SHORTAGE';
                $comment = 'Подтверждённая недостача в пересчитанной ячейке';
            } else {
                $classification = 'SURPLUS';
                $comment = 'Излишек в пересчитанной ячейке';
            }
        }

        $rows[] = [
            $cell,
            $product['sku'] ?? '',
            $barcode,
            $product['name'] ?? 'Неизвестный товар',
            $bookQty,
            $countedQty,
            $delta,
            $classification,
            $comment,
        ];
    }

    return $rows;
}

try {
    $catalogRows = readCsvFile(DATA_DIR . '/catalog.csv');
    $stockRows = readCsvFile(DATA_DIR . '/stock_cells.csv');
    $scanRows = readCsvFile(DATA_DIR . '/scans.csv');
    $countRows = readCsvFile(DATA_DIR . '/inventory_count.csv');

    $taskJson = file_get_contents(DATA_DIR . '/fbs_tasks.json');
    if ($taskJson === false) {
        throw new RuntimeException('Не удалось открыть файл fbs_tasks.json');
    }
    $taskData = json_decode(
        $taskJson,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!isset($taskData['tasks']) || !is_array($taskData['tasks'])) {
        throw new RuntimeException('В fbs_tasks.json отсутствует массив tasks');
    }
    $tasks = $taskData['tasks'];

    $catalogBySku = [];
    $catalogByBarcode = [];
    foreach ($catalogRows as $product) {
        if (
            isset($catalogBySku[$product['sku']])
            || isset($catalogByBarcode[$product['barcode']])
        ) {
            throw new RuntimeException(
                "Дублирующийся артикул или штрихкод товара {$product['sku']}"
            );
        }
        $catalogBySku[$product['sku']] = $product;
        $catalogByBarcode[$product['barcode']] = $product;
    }

    $stockBySku = [];
    foreach ($stockRows as $stock) {
        $stockBySku[$stock['sku']][$stock['cell']] =
            ($stockBySku[$stock['sku']][$stock['cell']] ?? 0) + (int) $stock['qty'];
    }

    [$picks, $unfulfillable] = buildPickingList(
        $catalogBySku,
        $catalogByBarcode,
        $stockBySku,
        $tasks
    );
    $assembly = checkAssembly($catalogByBarcode, $tasks, $scanRows);
    $inventory = compareInventory(
        $catalogBySku,
        $catalogByBarcode,
        $stockRows,
        $countRows
    );

    if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0777, true) && !is_dir(OUTPUT_DIR)) {
        throw new RuntimeException('Не удалось создать каталог output');
    }

    writeCsvFile(
        OUTPUT_DIR . '/picking_list.csv',
        ['cell', 'task_id', 'marketplace', 'ship_by', 'sku', 'name', 'barcode', 'qty'],
        $picks
    );
    writeCsvFile(
        OUTPUT_DIR . '/unfulfillable_tasks.csv',
        ['task_id', 'marketplace', 'ship_by', 'sku', 'name', 'required_qty', 'available_qty', 'shortage_qty'],
        $unfulfillable
    );
    writeCsvFile(
        OUTPUT_DIR . '/assembly_control.csv',
        ['task_id', 'status', 'severity', 'code', 'message'],
        $assembly
    );
    writeCsvFile(
        OUTPUT_DIR . '/inventory_discrepancies.csv',
        ['cell', 'sku', 'barcode', 'name', 'book_qty', 'counted_qty', 'delta', 'classification', 'comment'],
        $inventory
    );

    $problemTasks = [];
    foreach ($assembly as $row) {
        if ($row[1] === 'PROBLEM') {
            $problemTasks[$row[0]] = true;
        }
    }

    echo "Готово.\n";
    echo 'Строк в листе подбора: ' . count($picks) . "\n";
    echo 'Несобираемых заданий: ' . count(array_unique(array_column($unfulfillable, 0))) . "\n";
    echo 'Заданий с ошибками сборки: ' . count($problemTasks) . "\n";
    echo 'Инвентаризационных отклонений: ' . count($inventory) . "\n";
    echo 'Отчёты: ' . OUTPUT_DIR . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Ошибка: ' . $exception->getMessage() . "\n");
    exit(1);
}
