<?php
/**
 * Подсчёт 503 и долгих cron из access.log (inc2).
 * Запуск: php analyze-inc2.php [path/to/access.log]
 */

$log = $argv[1] ?? null;
if ($log === null) {
    fwrite(STDERR, "Usage: php analyze-inc2.php <path/to/access.log>\n");
    exit(64);
}
if (!is_readable($log)) {
    fwrite(STDERR, "Cannot read: {$log}\n");
    exit(1);
}

$byMinute = [];
$maxRt = [];

foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (!preg_match(
        '#\[(\d{2}/\w{3}/\d{4}:\d{2}:\d{2}):\d{2}#',
        $line,
        $m
    )) {
        continue;
    }
    $minute = $m[1]; // dd/Mon/yyyy:HH:MM
    if (!isset($byMinute[$minute])) {
        $byMinute[$minute] = [
            'total' => 0,
            '503' => 0,
            'cron' => 0,
            'bot' => 0,
            'bot_503' => 0,
            'checkout_503' => 0,
        ];
    }
    $byMinute[$minute]['total']++;

    $is503 = (bool) preg_match('#"\s+503\s+#', $line);
    if ($is503) {
        $byMinute[$minute]['503']++;
    }
    if (strpos($line, '/cron/') !== false) {
        $byMinute[$minute]['cron']++;
        if (preg_match('#rt=(\d+)ms#', $line, $rt)) {
            $maxRt[] = ['line' => trim($line), 'rt' => (int) $rt[1]];
        }
    }
    if (strpos($line, 'SomeCrawlerBot') !== false) {
        $byMinute[$minute]['bot']++;
        if ($is503) {
            $byMinute[$minute]['bot_503']++;
        }
    }
    if ($is503 && strpos($line, '/checkout') !== false) {
        $byMinute[$minute]['checkout_503']++;
    }
}

echo "=== Минуты с 503 ===\n";
printf("%-20s %6s %5s %5s %5s %8s %8s\n", 'minute', 'total', '503', 'cron', 'bot', 'bot_503', 'chk_503');
$totals = ['503' => 0, 'bot_503' => 0, 'checkout_503' => 0];
foreach ($byMinute as $minute => $s) {
    if ($s['503'] === 0) {
        continue;
    }
    printf(
        "%-20s %6d %5d %5d %5d %8d %8d\n",
        $minute,
        $s['total'],
        $s['503'],
        $s['cron'],
        $s['bot'],
        $s['bot_503'],
        $s['checkout_503']
    );
    $totals['503'] += $s['503'];
    $totals['bot_503'] += $s['bot_503'];
    $totals['checkout_503'] += $s['checkout_503'];
}
printf(
    "%-20s %6s %5d %5s %5s %8d %8d\n",
    'Итого',
    '',
    $totals['503'],
    '',
    '',
    $totals['bot_503'],
    $totals['checkout_503']
);

usort($maxRt, static function ($a, $b) {
    return $b['rt'] <=> $a['rt'];
});
echo "\n=== Cron по времени ответа (top) ===\n";
foreach (array_slice($maxRt, 0, 10) as $row) {
    echo sprintf("%6d ms  %s\n", $row['rt'], $row['line']);
}
