<?php
header('Content-Type: application/json');

$db_host = 'localhost';
$db_user = 'YOUR_DB_USER';
$db_pass = 'YOUR_DB_PASSWORD';
$db_name = 'YOUR_DB_NAME';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // NEU: Nur Einträge holen, bei denen show auf TRUE (1) steht!
    $stmt = $pdo->query("SELECT board, device, firmware, score, peak_burst, mix_latency_ms, submitted_at 
                         FROM wardrive_leaderboard 
                         WHERE showInBoard = 1 
                         ORDER BY score DESC 
                         LIMIT 100");
    $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rankings
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}