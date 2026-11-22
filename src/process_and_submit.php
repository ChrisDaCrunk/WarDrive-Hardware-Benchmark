<?php
header('Content-Type: application/json');

// 1. Datenbank-Verbindung anpassen
$db_host = 'localhost';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csv_file'])) {
    echo json_encode(['success' => false, 'error' => 'No CSV file uploaded']);
    exit;
}

$file = $_FILES['csv_file']['tmp_name'];
$fileSizeInBytes = filesize($file);
$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if (count($lines) < 2) {
    echo json_encode(['success' => false, 'error' => 'Invalid or empty CSV']);
    exit;
}

// Header parsen
$headerLine = $lines[0];
$parts = explode(',', $headerLine);
$wiggleVer = $parts[0] ?? "Unknown";
$model = "Unknown"; $board = "Unknown"; $app = "Unknown"; $appRelease = "";

foreach ($parts as $p) {
    $kv = explode('=', $p);
    if (count($kv) >= 2) {
        $key = strtolower(trim($kv[0]));
        $val = trim(implode('=', array_slice($kv, 1)));
        if ($key === 'model') $model = $val;
        if ($key === 'board') $board = $val;
        if ($key === 'app') $app = $val;
        if ($key === 'apprelease' || $key === 'release') $appRelease = $val;
    }
}
$firmware = trim($app . ' ' . $appRelease);
if (empty($firmware)) $firmware = "Unknown";

// Daten-Spalten finden
$colIndex = ['time' => -1, 'type' => -1];
$dataStartIndex = 1;

if (strpos($lines[1], "MAC") !== false || strpos($lines[1], "FirstSeen") !== false) {
    $columns = array_map('strtolower', array_map('trim', explode(',', $lines[1])));
    foreach ($columns as $idx => $col) {
        if (strpos($col, 'firstseen') !== false || strpos($col, 'time') !== false) $colIndex['time'] = $idx;
        if (strpos($col, 'type') !== false) $colIndex['type'] = $idx;
    }
    $dataStartIndex = 2;
}

$records = [];
$wifiRecords = [];
$bleRecords = [];

// Temporäres Array, um Timestamps zu gruppieren und Sekunden-Kollisionen aufzulösen
$timeBuckets = [];

for ($i = $dataStartIndex; $i < count($lines); $i++) {
    $row = explode(',', $lines[$i]);
    if (count($row) < 4) continue;

    $timeStr = "";
    if ($colIndex['time'] !== -1 && isset($row[$colIndex['time']]) && preg_match('/\d{4}-\d{2}-\d{2}/', $row[$colIndex['time']])) {
        $timeStr = trim($row[$colIndex['time']]);
    } else {
        foreach ($row as $val) {
            $val = trim($val);
            // Erkennt sowohl "YYYY-MM-DD HH:MM:SS" als auch mit Millisekunden "YYYY-MM-DD HH:MM:SS.mmm"
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(\.\d+)?$/', $val)) {
                $timeStr = $val;
                break;
            }
        }
    }

    if (empty($timeStr)) continue;

    // Timestamp in Millisekunden (unterstützt optional direkt übergebene Millisekunden)
    $ts = round(microtime(true) * 1000); // Fallback
    if (preg_match('/\.(\d+)/', $timeStr, $matches)) {
        // Wenn die CSV bereits Millisekunden enthält
        $ts = strtotime(preg_replace('/\.\d+/', '', $timeStr)) * 1000;
        $msPart = rpad($matches[1], 3, '0');
        $ts += (int)substr($msPart, 0, 3);
    } else {
        $ts = strtotime($timeStr) * 1000;
    }

    if (!$ts) continue;

    $rawType = ($colIndex['type'] !== -1 && isset($row[$colIndex['type']])) ? $row[$colIndex['type']] : $row[count($row) - 1];
    $typeStr = strtoupper(trim($rawType));

    $rec = ['time' => (float)$ts, 'type' => $typeStr];
    
    // Nach Sekunden-Timestamp gruppieren, um fehlende Millisekunden gleichmäßig zu verteilen
    $secKey = (int)floor($ts / 1000);
    $timeBuckets[$secKey][] = $rec;
}

// SYNTHETISCHE GLEICHVERTEILUNG (Anti-Sekunden-Kollision)
// Wenn das CSV keine Millisekunden hat, verteilen wir die Pakete innerhalb der Sekunde linear (z.B. bei 22 Paketen im Abstand von ~45ms)
foreach ($timeBuckets as $secKey => $bucket) {
    $countInSec = count($bucket);
    foreach ($bucket as $index => $rec) {
        // Falls keine echten Millisekunden da waren (alle haben exakt .000ms)
        if ($rec['time'] == $secKey * 1000 && $countInSec > 1) {
            // Verteile die Einträge gleichmäßig über 900ms der Sekunde
            $rec['time'] = ($secKey * 1000) + (($index / $countInSec) * 900);
        }
        
        $records[] = $rec;
        if (strpos($rec['type'], 'BLE') !== false || strpos($rec['type'], 'BT') !== false) {
            $bleRecords[] = $rec;
        } else {
            $wifiRecords[] = $rec;
        }
    }
}

if (count($records) === 0) {
    echo json_encode(['success' => false, 'error' => 'No valid timestamps found']);
    exit;
}

// Hilfsfunktion für string padding falls nötig
function rpad($val, $len, $pad) {
    return str_pad($val, $len, $pad);
}

// Sortieren nach Zeit
usort($records, function($a, $b) { return $a['time'] <=> $b['time']; });
usort($wifiRecords, function($a, $b) { return $a['time'] <=> $b['time']; });
usort($bleRecords, function($a, $b) { return $a['time'] <=> $b['time']; });

$totalDurationMs = end($records)['time'] - $records[0]['time'];
$totalDurationSec = $totalDurationMs / 1000;
$avgOps = $totalDurationSec > 0 ? (count($records) / $totalDurationSec) : 0;

// Benchmark Funktionen
function computeFastestWindow($recs, $windowSize = 50) {
    if (count($recs) < $windowSize) return null;
    $minAvg = INF;
    for ($i = 0; $i <= count($recs) - $windowSize; $i++) {
        $duration = $recs[$i + $windowSize - 1]['time'] - $recs[$i]['time'];
        $avg = $duration / ($windowSize - 1);
        if ($avg < $minAvg) $minAvg = $avg;
    }
    return $minAvg === INF ? null : $minAvg;
}

function findPeakWindow($recs, $windowMs = 3000) {
    if (empty($recs)) return 0;
    $maxCount = 0;
    $left = 0;
    for ($right = 0; $right < count($recs); $right++) {
        while ($recs[$right]['time'] - $recs[$left]['time'] > $windowMs) {
            $left++;
        }
        $count = $right - $left + 1;
        if ($count > $maxCount) $maxCount = $count;
    }
    return $maxCount;
}

$benchWifi = computeFastestWindow($wifiRecords, 50);
$benchBle = computeFastestWindow($bleRecords, 50);
$benchMix = computeFastestWindow($records, 50);
$peakBurst = findPeakWindow($records, 3000);

// Score Berechnung
$score = 0;
if ($benchMix && $peakBurst) {
    $burstRateSec = $peakBurst / 3;
    $burstScore = $burstRateSec * 10;
    $speedScore = (100.0 / $benchMix) * 5000;
    $throughputScore = $avgOps * 20;
    $score = (int)round($burstScore + $speedScore + $throughputScore);
}


// 4. In Datenbank speichern
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Prüfen, ob exakt diese Dateigröße (filebyte) bereits in der DB existiert
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM wardrive_leaderboard WHERE filebyte = ?");
    $checkStmt->execute([$fileSizeInBytes]);
    $isDuplicate = $checkStmt->fetchColumn() > 0;

    // Nur in die DB schreiben, wenn es KEIN Duplikat ist
    if (!$isDuplicate) {
        $stmt = $pdo->prepare("INSERT INTO wardrive_leaderboard 
            (board, device, firmware, score, peak_burst, mix_latency_ms, avg_rate, total_entries, wifi_count, ble_count, showInBoard, filebyte) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?)");
        
        $stmt->execute([
            $board, $model, $firmware, $score, $peakBurst, 
            $benchMix ?? 0, $avgOps, count($records), count($wifiRecords), count($bleRecords), $fileSizeInBytes
        ]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// 5. Ergebnis an Client zur Anzeige senden
echo json_encode([
    'success' => true,
    'data' => [
        'board' => $board,
        'device' => $model,
        'firmware' => $firmware,
        'wiggleVer' => $wiggleVer,
        'totalEntries' => count($records),
        'wifiCount' => count($wifiRecords),
        'bleCount' => count($bleRecords),
        'totalDurationSec' => $totalDurationSec,
        'avgOps' => $avgOps,
        'benchWifi' => $benchWifi,
        'benchBle' => $benchBle,
        'benchMix' => $benchMix,
        'peakBurst' => $peakBurst,
        'score' => $score
    ]
]);