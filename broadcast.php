<?php
require_once __DIR__ . '/../core/db.php';
$config = require __DIR__ . '/../config.php';

$token  = $config['bot_token'];
$apiUrl = "https://api.telegram.org/bot$token";

// 1. Берем мангу, добавленную за последние 24 часа. 
// ВАЖНО: У тебя в таблице manga должна быть колонка created_at
$stmt = $pdo->query("SELECT * FROM manga WHERE created_at >= NOW() - INTERVAL 1 DAY");
$newMangas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($newMangas)) {
    echo "Новинок за сегодня нет.\n";
    exit;
}

// 2. Получаем всех пользователей
$userStmt = $pdo->query("SELECT user_id FROM users");
$users = $userStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($users as $userId) {
    foreach ($newMangas as $manga) {
        
        // Проверяем, не прочитал ли пользователь эту мангу уже (на всякий случай)
        $check = $pdo->prepare("SELECT 1 FROM user_manga_status WHERE user_id = ? AND manga_id = ? AND status = 'read'");
        $check->execute([$userId, $manga['id']]);
        if ($check->fetch()) continue;

        $caption = "🔔 **Новинка дня!**\n\n📖 *" . $manga['title'] . "*\n\n" . ($manga['description'] ?? "");
        
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => '✅ Прочитано', 'callback_data' => 'read_' . $manga['id']]],
                [['text' => '🔗 Читать', 'url' => $manga['file_id']]]
            ]
        ]);

        // Отправляем с обложкой
        $sendUrl = $apiUrl . "/sendPhoto?chat_id=$userId&photo=" . urlencode($manga['file_id']) . "&caption=" . urlencode($caption) . "&parse_mode=Markdown&reply_markup=$keyboard";
        
        @file_get_contents($sendUrl);
    }
    echo "Рассылка для $userId завершена.\n";
}