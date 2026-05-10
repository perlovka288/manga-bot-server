<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

set_time_limit(900);
ini_set('memory_limit', '512M');

// Параметры БД
// ЭТО ВСТАВЛЯЕМ (подключение через общие файлы)
require_once __DIR__ . '/../core/db.php';
$config = require __DIR__ . '/../config.php';

$token  = $config['bot_token'];
$apiUrl = "https://api.telegram.org/bot$token";

// Создание таблицы архива если не существует
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_archive (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action_type VARCHAR(50) NOT NULL,
        action_text TEXT NOT NULL,
        action_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Создание таблицы меток (тегов) админов
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tags (
        user_id BIGINT PRIMARY KEY,
        tag_name VARCHAR(100) NOT NULL
    )");
} catch (Exception $e) {}

$superAdmins = [1710365896, 1181510470];

$admins = [1710365896, 1181510470];
try {
    $stmtAdmins = $pdo->query("SELECT user_id FROM bot_admins");
    while($row = $stmtAdmins->fetch()) {
        if(!in_array($row['user_id'], $admins)) $admins[] = $row['user_id'];
    }
} catch (Exception $e) {}

$imgbbKey = '58ff4596fd55028a81cbf8c4e38388e1';

// --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---

function tgPost($url, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function downloadFile($url) {
    $tempName = tempnam(sys_get_temp_dir(), 'manga_img_');
    $ch = curl_init($url);
    $fp = fopen($tempName, 'wb');
    if (!$fp) return false;

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($httpCode == 200 && filesize($tempName) > 0) return $tempName;
    if (file_exists($tempName)) @unlink($tempName);
    return false;
}

function uploadToImgbb($tempFile, $apiKey) {
    $imageData = base64_encode(file_get_contents($tempFile));
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $apiKey, 'image' => $imageData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    $res = json_decode($response, true);
    return $res['data']['url'] ?? false;
}

// =============================================
// НОВОЕ: Получить ImgBB URL промо-фото (кешируется в БД)
// =============================================
function getPromoImageUrl($pdo, $imgbbKey) {
    // Проверяем кеш в БД
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL
        )");
        $stmt = $pdo->prepare("SELECT setting_value FROM bot_settings WHERE setting_key = 'promo_imgbb_url'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
    } catch (Exception $e) {}

    // Загружаем промо-фото на ImgBB
    $promoPath = __DIR__ . '/promo.jpg';
    if (!file_exists($promoPath)) return null;

    $imageData = base64_encode(file_get_contents($promoPath));
    $ch = curl_init('https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['key' => $imgbbKey, 'image' => $imageData]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($response, true);
    $url = $res['data']['url'] ?? null;

    // Кешируем в БД
    if ($url) {
        try {
            $pdo->prepare("REPLACE INTO bot_settings (setting_key, setting_value) VALUES ('promo_imgbb_url', ?)")
                ->execute([$url]);
        } catch (Exception $e) {}
    }

    return $url;
}

// =============================================
// Читаем Central Directory из ZIP (без ZipArchive),
// получаем mtime каждого файла, сортируем,
// затем распаковываем через системный unzip.
// Работает на Railway и любом Linux без php-zip.
// Поддерживает ZIP с вложенными папками любой глубины.
// =============================================
function extractAndSortZip($zipPath, $extractDir) {
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    
    $raw = file_get_contents($zipPath);
    if ($raw === false) return false;
    $len = strlen($raw);

    // --- 1. Ищем EOCD ---
    $eocdPos = false;
    for ($i = $len - 22; $i >= max(0, $len - 65557); $i--) {
        if (substr($raw, $i, 4) === "\x50\x4b\x05\x06") {
            $eocdPos = $i;
            break;
        }
    }
    if ($eocdPos === false) return false;

    $cdCount  = unpack('v', substr($raw, $eocdPos + 10, 2))[1];
    $cdOffset = unpack('V', substr($raw, $eocdPos + 16, 4))[1];

    // --- 2. Читаем Central Directory ---
    $entries = [];
    $pos = $cdOffset;

    for ($n = 0; $n < $cdCount; $n++) {
        if ($pos + 46 > $len) break;
        if (substr($raw, $pos, 4) !== "\x50\x4b\x01\x02") break;

        $modTime  = unpack('v', substr($raw, $pos + 12, 2))[1];
        $modDate  = unpack('v', substr($raw, $pos + 14, 2))[1];
        $crc32    = unpack('V', substr($raw, $pos + 16, 4))[1];
        $compSize = unpack('V', substr($raw, $pos + 20, 4))[1];
        $origSize = unpack('V', substr($raw, $pos + 24, 4))[1];
        $fnameLen = unpack('v', substr($raw, $pos + 28, 2))[1];
        $extraLen = unpack('v', substr($raw, $pos + 30, 2))[1];
        $cmtLen   = unpack('v', substr($raw, $pos + 32, 2))[1];
        $compress = unpack('v', substr($raw, $pos + 10, 2))[1];
        $localHdrOffset = unpack('V', substr($raw, $pos + 42, 4))[1];

        $fname = substr($raw, $pos + 46, $fnameLen);
        $pos  += 46 + $fnameLen + $extraLen + $cmtLen;

        if (substr($fname, -1) === '/') continue;
        $base = basename($fname);
        if ($base === '' || $base[0] === '.') continue;

        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) continue;

        // Читаем Local File Header чтобы найти offset данных
        $lhPos = $localHdrOffset;
        if ($lhPos + 30 > $len) continue;
        if (substr($raw, $lhPos, 4) !== "\x50\x4b\x03\x04") continue;

        $lFnameLen = unpack('v', substr($raw, $lhPos + 26, 2))[1];
        $lExtraLen = unpack('v', substr($raw, $lhPos + 28, 2))[1];
        $dataOffset = $lhPos + 30 + $lFnameLen + $lExtraLen;

        // MS-DOS время → Unix timestamp
        $second = ($modTime & 0x1F) * 2;
        $minute = ($modTime >> 5) & 0x3F;
        $hour   = ($modTime >> 11) & 0x1F;
        $day    = $modDate & 0x1F;
        $month  = ($modDate >> 5) & 0x0F;
        $year   = (($modDate >> 9) & 0x7F) + 1980;
        $mtime  = mktime($hour, $minute, $second, $month, $day, $year);

        $entries[] = [
            'name'       => $fname,
            'base'       => $base,
            'mtime'      => $mtime,
            'compress'   => $compress,
            'compSize'   => $compSize,
            'origSize'   => $origSize,
            'dataOffset' => $dataOffset,
        ];
    }

    if (empty($entries)) return false;

    // --- 3. Сортируем по mtime, затем по имени ---
    usort($entries, function($a, $b) {
        if ($a['mtime'] === $b['mtime']) {
            return strnatcasecmp($a['base'], $b['base']);
        }
        return $a['mtime'] - $b['mtime'];
    });

    // --- 4. Распаковываем вручную (без unzip, без ZipArchive) ---
    $extractedPaths = [];

    foreach ($entries as $entry) {
        $outPath = $extractDir . '/' . $entry['base'];

        if ($entry['compress'] === 0) {
            // Метод 0: Stored — просто копируем байты
            $fileData = substr($raw, $entry['dataOffset'], $entry['origSize']);
        } elseif ($entry['compress'] === 8) {
            // Метод 8: Deflate — распаковываем через gzinflate
            $compressed = substr($raw, $entry['dataOffset'], $entry['compSize']);
            $fileData = @gzinflate($compressed);
            if ($fileData === false) continue;
        } else {
            // Другие методы сжатия не поддерживаем
            continue;
        }

        if (file_put_contents($outPath, $fileData) === false) continue;
        $extractedPaths[] = $outPath;
    }

    return empty($extractedPaths) ? false : $extractedPaths;
}


function createTelegraphPage($title, $fileIds, $token, $apiUrl) {
    global $imgbbKey, $pdo;
    $nodes = [];
    $fileIds = array_unique($fileIds);

    // Промо-фото первым слайдом
    $promoUrl = getPromoImageUrl($pdo, $imgbbKey);
    if ($promoUrl) {
        $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $promoUrl]];
    }

    foreach ($fileIds as $fId) {
        $fileDataJson = @file_get_contents($apiUrl . "/getFile?file_id=" . $fId);
        $fileData = json_decode($fileDataJson, true);
        if (!isset($fileData['result']['file_path'])) continue;

        $fullPath = "https://api.telegram.org/file/bot$token/" . $fileData['result']['file_path'];
        $tempFile = downloadFile($fullPath);
        if (!$tempFile) continue;

        $imgUrl = uploadToImgbb($tempFile, $imgbbKey);
        @unlink($tempFile);
        if (!$imgUrl) continue;

        $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $imgUrl]];
    }

    if (empty($nodes)) return false;

    $postData = [
        'title'          => $title,
        'author_name'    => 'Manga Reader',
        'content'        => json_encode($nodes),
        'return_content' => true
    ];

    $ch = curl_init("https://api.telegra.ph/createPage?access_token=192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawResponse = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($rawResponse, true);
    return $res['result']['url'] ?? false;
}

// Получить имя (тег) администратора
function getAdminTag($pdo, $adminId) {
    try {
        $stmt = $pdo->prepare("SELECT tag_name FROM admin_tags WHERE user_id = ?");
        $stmt->execute([$adminId]);
        $row = $stmt->fetch();
        return $row ? $row['tag_name'] : "ID: $adminId";
    } catch (Exception $e) {
        return "ID: $adminId";
    }
}

// Логировать действие в архив
function logArchive($pdo, $type, $text, $by) {
    try {
        $pdo->prepare("INSERT INTO bot_archive (action_type, action_text, action_by) VALUES (?, ?, ?)")
            ->execute([$type, $text, $by]);
    } catch (Exception $e) {}
}

function getMainMenu($chatId, $admins) {
    $rows = [
        [['text' => '🔍 Найти мангу'], ['text' => '📚 Моя библиотека']],
        [['text' => '🔥 Топ по лайкам'], ['text' => '🎲 Случайная манга']],
        [['text' => '💡 Предложить мангу']]
    ];
    if (in_array($chatId, $admins)) {
        $rows[] = [['text' => '⚙️ АДМИН-ПАНЕЛЬ']];
    }
    return json_encode(['keyboard' => $rows, 'resize_keyboard' => true]);
}

// ИЗМЕНЕНО: добавлена кнопка «✏️ Редактирование»
$adminKeyboard = json_encode([
    'keyboard' => [
        [['text' => '➕ Добавить через альбом'], ['text' => '📦 Добавить через ZIP']],
        [['text' => '✏️ Редактирование'],         ['text' => '📊 Статистика админов']],
        [['text' => '📥 Читать предложку'],        ['text' => '🗂 Архив бота']],
        [['text' => '❓ FAQ и Команды'],            ['text' => '🔙 Выйти в режим читателя']]
    ],
    'resize_keyboard' => true
]);

$content = file_get_contents("php://input");
$update  = json_decode($content, true);
if (!$update) exit;

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId   = $callback['message']['chat']['id'];
    $data     = $callback['data'];
    $msgId    = $callback['message']['message_id'];

    if (strpos($data, 'vote_') === 0) {
        $parts = explode('_', $data);
        $type = $parts[1];
        $mId = $parts[2];

        $check = $pdo->prepare("SELECT vote_type FROM votes WHERE user_id = ? AND manga_id = ?");
        $check->execute([$chatId, $mId]);
        $existing = $check->fetch();

        if ($existing) {
            if ($existing['vote_type'] !== $type) {
                $oldCol = ($existing['vote_type'] == 'like') ? 'likes' : 'dislikes';
                $newCol = ($type == 'like') ? 'likes' : 'dislikes';
                $pdo->prepare("UPDATE votes SET vote_type = ? WHERE user_id = ? AND manga_id = ?")->execute([$type, $chatId, $mId]);
                $pdo->prepare("UPDATE manga SET $oldCol = $oldCol - 1, $newCol = $newCol + 1 WHERE id = ?")->execute([$mId]);
                $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                $stmt->execute([$mId]);
                updateMangaMessage($chatId, $msgId, $stmt->fetch(), $apiUrl);
            }
        } else {
            $col = ($type == 'like') ? 'likes' : 'dislikes';
            $pdo->prepare("INSERT INTO votes (user_id, manga_id, vote_type) VALUES (?, ?, ?)")->execute([$chatId, $mId, $type]);
            $pdo->prepare("UPDATE manga SET $col = $col + 1 WHERE id = ?")->execute([$mId]);
            $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
            $stmt->execute([$mId]);
            updateMangaMessage($chatId, $msgId, $stmt->fetch(), $apiUrl);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '✅ Голос учтён!']);
    }

    if (strpos($data, 'stat_') === 0) {
        $parts = explode('_', $data);
        $status = $parts[1];
        $mId = $parts[2];
        $pdo->prepare("INSERT INTO user_manga_status (user_id, manga_id, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = ?")->execute([$chatId, $mId, $status, $status]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '📚 Обновлено в библиотеке!']);
    }

    if (strpos($data, 'show_') === 0) {
        $id = str_replace('show_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([$id]);
        sendMangaCard($chatId, $stmt->fetch(), $apiUrl);
    }

    if (strpos($data, 'search_page_') === 0) {
        $parts = explode('_', $data);
        $page = (int)array_pop($parts);
        $query = urldecode(implode('_', array_slice($parts, 2)));
        $c = getSearchData($pdo, $query, $page);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'text'         => $c['text'],
            'reply_markup' => json_decode($c['reply_markup']),
            'parse_mode'   => 'Markdown'
        ]);
    }

    // =============================================
    // НОВОЕ: РЕДАКТИРОВАНИЕ — пагинация каталога
    // =============================================
    if (strpos($data, 'edit_page_') === 0) {
        $parts = explode('_', $data);
        $page  = (int)array_pop($parts);
        $query = urldecode(implode('_', array_slice($parts, 2)));
        $c = getEditSearchData($pdo, $query, $page);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'text'         => $c['text'],
            'reply_markup' => json_decode($c['reply_markup']),
            'parse_mode'   => 'Markdown'
        ]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
    }

    // НОВОЕ: РЕДАКТИРОВАНИЕ — выбор манги → меню действий
    if (strpos($data, 'edit_manga_') === 0) {
        $mId = str_replace('edit_manga_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) sendEditMangaMenu($chatId, $m, $apiUrl);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
    }

    // НОВОЕ: РЕДАКТИРОВАНИЕ — нажатие кнопки изменения поля
    if (strpos($data, 'editfield_') === 0) {
        $parts = explode('_', $data);
        $field = $parts[1]; // title / desc / link / cover
        $mId   = $parts[2];
        $fieldLabels = [
            'title' => 'название',
            'desc'  => 'описание',
            'link'  => 'ссылку (Telegraph URL)',
            'cover' => 'обложку (отправьте фото)',
        ];
        $label = $fieldLabels[$field] ?? $field;
        $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, ?, ?)")
            ->execute([$chatId, 'edit_wait_' . $field, json_encode(['manga_id' => $mId])]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        sendSimpleMsg($chatId, "✏️ *Введите новое $label* для манги:\n\n_Отправьте сообщение с новым значением:_", $apiUrl);
    }

    if (strpos($data, 'delete_confirm_') === 0) {
        $mId = str_replace('delete_confirm_', '', $data);
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) {
            $kb = ['inline_keyboard' => [[
                ['text' => '✅ Да, удалить', 'callback_data' => 'delete_yes_' . $mId],
                // ИЗМЕНЕНО: передаём mId в cancel чтобы вернуться в меню редактирования
                ['text' => '❌ Отмена',      'callback_data' => 'delete_cancel_' . $mId]
            ]]];
            tgPost($apiUrl . "/editMessageText", [
                'chat_id'      => $chatId,
                'message_id'   => $msgId,
                'text'         => "🗑 Вы уверены, что хотите удалить мангу *{$m['title']}*?\n\n_Это действие необратимо._",
                'parse_mode'   => 'Markdown',
                'reply_markup' => $kb
            ]);
        }
    }

    if (strpos($data, 'delete_yes_') === 0) {
        $mId = str_replace('delete_yes_', '', $data);
        $stmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        $pdo->prepare("DELETE FROM manga WHERE id = ?")->execute([$mId]);
        logArchive($pdo, 'delete_manga', "Удалена манга: " . ($m['title'] ?? '?'), $chatId);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'    => $chatId,
            'message_id' => $msgId,
            'text'       => "✅ Манга *{$m['title']}* успешно удалена из базы.",
            'parse_mode' => 'Markdown'
        ]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '🗑 Удалено']);
    }

    // ИЗМЕНЕНО: delete_cancel теперь с ID — возврат в меню редактирования
    if (strpos($data, 'delete_cancel_') === 0) {
        $mId = str_replace('delete_cancel_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
        $stmt->execute([$mId]);
        $m = $stmt->fetch();
        if ($m) {
            sendEditMangaMenuEdit($chatId, $msgId, $m, $apiUrl);
        }
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '❌ Отменено']);
    }

    // Старый delete_cancel без ID — оставлен для совместимости
    if ($data == 'delete_cancel') {
        tgPost($apiUrl . "/editMessageText", ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => "❌ Удаление отменено."]);
    }

    // =============================================
    // ПРЕДЛОЖКА: просмотр с кнопками ответа
    // =============================================
    if (strpos($data, 'view_suggest_') === 0) {
        $sId = str_replace('view_suggest_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'read' WHERE id = ?")->execute([$sId]);
            $kb = ['inline_keyboard' => [[
                ['text' => '✅ Рассмотрено', 'callback_data' => 'done_suggest_' . $sId]
            ]]];
            tgPost($apiUrl . "/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => "📩 *Предложение от пользователя:*\n\n_" . $s['text'] . "_",
                'parse_mode'   => 'Markdown',
                'reply_markup' => $kb
            ]);
        }
    }

    // Начать процесс ответа на предложение
    if (strpos($data, 'reply_suggest_') === 0) {
        $sId = str_replace('reply_suggest_', '', $data);
        $stmt = $pdo->prepare("SELECT user_id FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_suggest_reply', ?)")
                ->execute([$chatId, json_encode(['suggest_id' => $sId, 'target_user' => $s['user_id']])]);
            tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
            sendSimpleMsg($chatId, "✍️ *Напишите ваш ответ пользователю:*\n\n_Он получит уведомление, что администратор ответил на его предложение._", $apiUrl);
        }
    }

    // Пометить предложение как прочитано — уведомить пользователя
    if (strpos($data, 'done_suggest_') === 0) {
        $sId = str_replace('done_suggest_', '', $data);
        $stmt = $pdo->prepare("SELECT * FROM suggestions WHERE id = ?");
        $stmt->execute([$sId]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("UPDATE suggestions SET status = 'done' WHERE id = ?")->execute([$sId]);
            tgPost($apiUrl . "/sendMessage", [
                'chat_id'    => $s['user_id'],
                'text'       => "📬 *Ваше предложение рассмотрено!*\n\nСпасибо за активность — администраторы ознакомились с вашим сообщением. 🙏",
                'parse_mode' => 'Markdown'
            ]);
            tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '✅ Пользователь уведомлён']);
        }
    }

    // =============================================
    // ТЕГ: выбор айди админа для установки метки
    // =============================================
    if (strpos($data, 'settag_') === 0) {
        $targetAdminId = str_replace('settag_', '', $data);
        $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_tag_name', ?)")
            ->execute([$chatId, json_encode(['target_admin' => $targetAdminId])]);
        tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id']]);
        sendSimpleMsg($chatId, "✏️ *Введите имя (метку) для администратора* `$targetAdminId`:\n\n_Например: Максим, Даша, Главный_", $apiUrl);
    }

    // Архив: навигация по страницам
    if (strpos($data, 'archive_page_') === 0) {
        $page = (int)str_replace('archive_page_', '', $data);
        $archiveData = getArchiveData($pdo, $admins, $page);
        tgPost($apiUrl . "/editMessageText", [
            'chat_id'      => $chatId,
            'message_id'   => $msgId,
            'text'         => $archiveData['text'],
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_decode($archiveData['reply_markup'])
        ]);
    }

    // =============================================
    // НОВОЕ: привязка обложки к конкретной манге
    // =============================================
    if (strpos($data, 'bind_cover_') === 0) {
        $mId = str_replace('bind_cover_', '', $data);

        // Достаём сохранённый file_id обложки из temp_data
        $stateRow = $pdo->prepare("SELECT pages FROM temp_data WHERE user_id = ? AND step = 'wait_cover_choice'");
        $stateRow->execute([$chatId]);
        $stateRow = $stateRow->fetch();

        if ($stateRow) {
            $stateData = json_decode($stateRow['pages'], true);
            $coverId   = $stateData['cover_file_id'] ?? null;

            if ($coverId) {
                $pdo->prepare("UPDATE manga SET cover_id = ? WHERE id = ?")->execute([$coverId, $mId]);

                $titleStmt = $pdo->prepare("SELECT title FROM manga WHERE id = ?");
                $titleStmt->execute([$mId]);
                $titleRow = $titleStmt->fetch();

                logArchive($pdo, 'set_cover', "Установлена обложка для манги: " . ($titleRow['title'] ?? '?'), $chatId);
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);

                tgPost($apiUrl . "/answerCallbackQuery", ['callback_query_id' => $callback['id'], 'text' => '✅ Обложка привязана!']);
                tgPost($apiUrl . "/editMessageCaption", [
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'caption'    => "✅ *Обложка успешно привязана* к манге *{$titleRow['title']}*!",
                    'parse_mode' => 'Markdown'
                ]);
            }
        }
    }

    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId  = $message['chat']['id'];
    $text    = $message['text'] ?? $message['caption'] ?? '';

    $stmtState = $pdo->prepare("SELECT * FROM temp_data WHERE user_id = ?");
    $stmtState->execute([$chatId]);
    $userState = $stmtState->fetch();

    if (in_array($chatId, $admins)) {

        // =============================================
        // Ответ на предложение — ПЕРВЫМ делом до всех команд
        // =============================================
        if ($userState && $userState['step'] == 'wait_suggest_reply') {
            $stateData = json_decode($userState['pages'], true);
            $targetUser = $stateData['target_user'] ?? null;
            $sId = $stateData['suggest_id'] ?? null;
            if ($targetUser && !empty($text)) {
                tgPost($apiUrl . "/sendMessage", [
                    'chat_id'    => $targetUser,
                    'text'       => "📨 *Вам пришёл ответ от администратора!*\n\n_" . $text . "_",
                    'parse_mode' => 'Markdown'
                ]);
                if ($sId) {
                    $pdo->prepare("UPDATE suggestions SET status = 'answered' WHERE id = ?")->execute([$sId]);
                }
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                global $adminKeyboard;
                sendSimpleMsg($chatId, "✅ *Ответ отправлен* пользователю!", $apiUrl, $adminKeyboard);
            }
            exit;
        }

        // Ввод метки для администратора — ПЕРВЫМ делом до всех команд
        if ($userState && $userState['step'] == 'wait_tag_name') {
            $stateData = json_decode($userState['pages'], true);
            $targetAdminId = $stateData['target_admin'] ?? null;
            if ($targetAdminId && !empty($text)) {
                $pdo->prepare("INSERT INTO admin_tags (user_id, tag_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE tag_name = ?")
                    ->execute([$targetAdminId, $text, $text]);
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                logArchive($pdo, 'set_tag', "Установлена метка «$text» для ID $targetAdminId", $chatId);
                sendSimpleMsg($chatId, "✅ *Метка установлена!*\n\nАдминистратор `$targetAdminId` теперь называется: *$text*", $apiUrl);
            }
            exit;
        }

        // =============================================
        // НОВОЕ: Обработка ввода при редактировании манги
        // =============================================
        if ($userState && strpos($userState['step'], 'edit_wait_') === 0) {
            $field     = str_replace('edit_wait_', '', $userState['step']);
            $stateData = json_decode($userState['pages'], true);
            $mId       = $stateData['manga_id'] ?? null;

            if ($mId) {
                if ($field === 'cover') {
                    if (isset($message['photo'])) {
                        $coverId = end($message['photo'])['file_id'];
                        $pdo->prepare("UPDATE manga SET cover_id = ? WHERE id = ?")->execute([$coverId, $mId]);
                        $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                        $stmt->execute([$mId]);
                        $m = $stmt->fetch();
                        logArchive($pdo, 'edit_manga', "Изменена обложка манги: " . ($m['title'] ?? '?'), $chatId);
                        $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                        tgPost($apiUrl . "/sendPhoto", [
                            'chat_id'    => $chatId,
                            'photo'      => $coverId,
                            'caption'    => "✅ *Обложка обновлена!*\n\nМанга: *{$m['title']}*",
                            'parse_mode' => 'Markdown'
                        ]);
                        sendEditMangaMenu($chatId, $m, $apiUrl);
                    } else {
                        sendSimpleMsg($chatId, "🖼 Пожалуйста, отправьте *фото* как новую обложку:", $apiUrl);
                    }
                } else {
                    if (!empty($text)) {
                        $colMap = ['title' => 'title', 'desc' => 'description', 'link' => 'file_id'];
                        $col = $colMap[$field] ?? null;
                        if ($col) {
                            $newVal = $text;
                            if ($col === 'title' && strpos($newVal, '❤️') !== 0) {
                                $newVal = '❤️ ' . $newVal;
                            }
                            $pdo->prepare("UPDATE manga SET $col = ? WHERE id = ?")->execute([$newVal, $mId]);
                            $stmt = $pdo->prepare("SELECT * FROM manga WHERE id = ?");
                            $stmt->execute([$mId]);
                            $m = $stmt->fetch();
                            $fieldNames = ['title' => 'Название', 'desc' => 'Описание', 'link' => 'Ссылка'];
                            $fieldName  = $fieldNames[$field] ?? $field;
                            logArchive($pdo, 'edit_manga', "Изменено поле «$fieldName» манги: " . ($m['title'] ?? '?'), $chatId);
                            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                            sendSimpleMsg($chatId, "✅ *$fieldName обновлено!*", $apiUrl);
                            sendEditMangaMenu($chatId, $m, $apiUrl);
                        }
                    } else {
                        sendSimpleMsg($chatId, "⚠️ Введите непустое значение:", $apiUrl);
                    }
                }
            }
            exit;
        }

        // НОВОЕ: поиск по каталогу в режиме редактирования (текстовый ввод)
        if ($userState && $userState['step'] == 'wait_edit_search') {
            $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
            $c = getEditSearchData($pdo, trim($text), 0);
            sendSimpleMsg($chatId, $c['text'], $apiUrl, $c['reply_markup']);
            exit;
        }

        // =============================================
        // НОВОЕ: Фото с подписью +название — привязка обложки
        // =============================================
        if (isset($message['photo']) && !empty($text) && strpos(trim($text), '+') === 0) {
            $searchTitle = trim(mb_substr(trim($text), 1)); // всё после +
            $coverId     = end($message['photo'])['file_id'];

            if (!empty($searchTitle)) {
                $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? LIMIT 5");
                $stmt->execute(['%' . $searchTitle . '%']);
                $found = $stmt->fetchAll();

                if (count($found) === 1) {
                    // Один результат — привязываем сразу
                    $pdo->prepare("UPDATE manga SET cover_id = ? WHERE id = ?")->execute([$coverId, $found[0]['id']]);
                    logArchive($pdo, 'set_cover', "Установлена обложка для манги: {$found[0]['title']}", $chatId);
                    tgPost($apiUrl . "/sendPhoto", [
                        'chat_id'    => $chatId,
                        'photo'      => $coverId,
                        'caption'    => "✅ *Обложка привязана* к манге *{$found[0]['title']}*!",
                        'parse_mode' => 'Markdown'
                    ]);
                } elseif (count($found) > 1) {
                    // Несколько результатов — показываем кнопки выбора
                    $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_cover_choice', ?)")
                        ->execute([$chatId, json_encode(['cover_file_id' => $coverId])]);

                    $btns = [];
                    foreach ($found as $r) {
                        $btns[] = [['text' => '📘 ' . $r['title'], 'callback_data' => 'bind_cover_' . $r['id']]];
                    }

                    tgPost($apiUrl . "/sendPhoto", [
                        'chat_id'      => $chatId,
                        'photo'        => $coverId,
                        'caption'      => "🔎 *Найдено несколько манг по запросу «$searchTitle»*\n\n_Выберите, к какой привязать эту обложку:_",
                        'parse_mode'   => 'Markdown',
                        'reply_markup' => json_encode(['inline_keyboard' => $btns])
                    ]);
                } else {
                    tgPost($apiUrl . "/sendPhoto", [
                        'chat_id'    => $chatId,
                        'photo'      => $coverId,
                        'caption'    => "❌ *Манга «$searchTitle» не найдена в базе.*\n\n_Проверьте название и попробуйте снова._",
                        'parse_mode' => 'Markdown'
                    ]);
                }
                exit;
            }
        }

        // =============================================
        // ZIP: получен документ — обрабатываем если ждём ZIP
        // =============================================
        if (isset($message['document'])) {
            $doc      = $message['document'];
            $mimeType = $doc['mime_type'] ?? '';
            $fileName = $doc['file_name'] ?? '';

            $isZip = ($mimeType === 'application/zip'
                   || $mimeType === 'application/x-zip-compressed'
                   || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'zip');

            if ($isZip && $userState && $userState['step'] === 'wait_zip') {

                sendSimpleMsg($chatId, "📦 _ZIP получен. Распаковываю и сортирую по дате..._", $apiUrl);

                // Скачиваем ZIP через Telegram
                $fileInfoJson = @file_get_contents($apiUrl . "/getFile?file_id=" . $doc['file_id']);
                $fileInfo     = json_decode($fileInfoJson, true);

                if (!isset($fileInfo['result']['file_path'])) {
                    sendSimpleMsg($chatId,
                        "❌ *Не удалось получить файл от Telegram.*\n\n_Telegram Bot API не отдаёт файлы больше 20 МБ. Попробуйте разбить архив на части._",
                        $apiUrl);
                    exit;
                }

                $zipUrl  = "https://api.telegram.org/file/bot$token/" . $fileInfo['result']['file_path'];
                $zipTemp = downloadFile($zipUrl);

                if (!$zipTemp) {
                    sendSimpleMsg($chatId, "❌ *Ошибка скачивания ZIP-архива.*", $apiUrl);
                    exit;
                }

                // Создаём временную папку для извлечения
                $extractDir = sys_get_temp_dir() . '/manga_zip_' . $chatId . '_' . time();
                mkdir($extractDir, 0777, true);

                // Извлекаем и сортируем по mtime из ZIP-заголовка
                $sortedFiles = extractAndSortZip($zipTemp, $extractDir);
                @unlink($zipTemp);

                if (!$sortedFiles || empty($sortedFiles)) {
                    @rmdir($extractDir);
                    sendSimpleMsg($chatId,
                        "❌ *В ZIP не найдено ни одного изображения.*\n\n_Поддерживаются: jpg, jpeg, png, webp, gif_",
                        $apiUrl);
                    exit;
                }

                $total = count($sortedFiles);
                sendSimpleMsg($chatId, "🖼 _Найдено $total фото. Загружаю на ImgBB..._", $apiUrl);

                // Загружаем на ImgBB по порядку
                $imgUrls = [];
                foreach ($sortedFiles as $imgPath) {
                    $url = uploadToImgbb($imgPath, $imgbbKey);
                    @unlink($imgPath); // удаляем сразу после загрузки
                    if ($url) $imgUrls[] = $url;
                }
                @rmdir($extractDir);

                if (empty($imgUrls)) {
                    sendSimpleMsg($chatId,
                        "❌ *Не удалось загрузить изображения на ImgBB.*\n\n_Проверьте API-ключ ImgBB или попробуйте ещё раз._",
                        $apiUrl);
                    exit;
                }

                // Сохраняем ImgBB URLs, переходим к вводу названия
                $pdo->prepare("UPDATE temp_data SET step = 'wait_title_zip', pages = ? WHERE user_id = ?")
                    ->execute([json_encode(['imgbb_urls' => $imgUrls]), $chatId]);

                sendSimpleMsg($chatId,
                    "✅ *" . count($imgUrls) . " фото загружено!*\n\n*Шаг 2 из 4:* Введите *название* манги:",
                    $apiUrl);
                exit;
            }

            // ZIP прислан не в нужном состоянии — подсказываем
            if ($isZip) {
                sendSimpleMsg($chatId,
                    "📦 Чтобы добавить мангу через ZIP — нажмите кнопку *«📦 Добавить через ZIP»* в меню.",
                    $apiUrl);
                exit;
            }
        }

        // =============================================
        // ТЕГ+ — вывод кнопок с айди всех админов
        // =============================================
        if (trim($text) === 'тег+' || trim($text) === 'Тег+') {
            $btns = [];
            foreach ($admins as $admId) {
                $tagName = getAdminTag($pdo, $admId);
                $btns[] = [['text' => "👤 $tagName  ($admId)", 'callback_data' => 'settag_' . $admId]];
            }
            tgPost($apiUrl . "/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => "🏷 *Управление метками администраторов*\n\n_Выберите администратора, чтобы установить или изменить его имя:_",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $btns])
            ]);
            exit;
        }

        // Старая команда +тег — тоже оставим для совместимости
        if (trim($text) === '+тег') {
            $tagList = "🏷 *ID Администраторов:*\n\n";
            foreach ($admins as $admId) {
                $tagName = getAdminTag($pdo, $admId);
                $tagList .= "👤 *$tagName* — `$admId`\n";
            }
            sendSimpleMsg($chatId, $tagList . "\n_Нажмите на ID, чтобы скопировать_", $apiUrl);
            exit;
        }

        // Назначение нового администратора
        if (strpos(trim($text), 'админ+') === 0 || strpos(trim($text), 'Админ+') === 0) {
            if (in_array($chatId, $superAdmins)) {
                $newAdminId = trim(str_ireplace('админ+', '', $text));
                if (is_numeric($newAdminId)) {
                    $pdo->prepare("INSERT IGNORE INTO bot_admins (user_id) VALUES (?)")->execute([$newAdminId]);
                    logArchive($pdo, 'new_admin', "Назначен новый администратор: ID $newAdminId", $chatId);
                    sendSimpleMsg($chatId, "✅ Пользователь `$newAdminId` теперь *администратор*.", $apiUrl);
                    tgPost($apiUrl . "/sendMessage", ['chat_id' => $newAdminId, 'text' => "🎉 *Поздравляем!* Вас назначили администратором! Нажмите /start", 'parse_mode' => 'Markdown']);
                }
            } else {
                sendSimpleMsg($chatId, "❌ *Недостаточно прав.*\nТолько главные администраторы могут выдавать права.", $apiUrl);
            }
            exit;
        }

        // Рассылка всем
        if (strpos(trim($text), 'Всем+') === 0) {
            $broadcastText = trim(str_replace('Всем+', '', $text));
            if (!empty($broadcastText)) {
                $stmtUsers = $pdo->query("SELECT DISTINCT user_id FROM user_manga_status UNION SELECT DISTINCT user_id FROM votes");
                $allUsers = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allUsers as $uId) {
                    tgPost($apiUrl . "/sendMessage", ['chat_id' => $uId, 'text' => "📢 *ОБЪЯВЛЕНИЕ:*\n\n$broadcastText", 'parse_mode' => 'Markdown']);
                    usleep(50000);
                }
                logArchive($pdo, 'broadcast', "Рассылка: $broadcastText", $chatId);
                sendSimpleMsg($chatId, "✅ *Рассылка завершена* для всех активных пользователей.", $apiUrl);
            }
            exit;
        }

        // =============================================
        // НОВОЕ: Быстрое добавление манги через + (текст без фото)
        // Формат: +Название | ссылка телеграф | описание
        // =============================================
        if (!isset($message['photo']) && strpos(trim($text), '+') === 0 && strpos($text, '|') !== false) {
            $raw   = trim(mb_substr(trim($text), 1)); // убираем +
            $parts = array_map('trim', explode('|', $raw));

            if (count($parts) >= 3) {
                $mTitle = $parts[0];
                $mLink  = $parts[1];
                $mDesc  = $parts[2];

                $pdo->prepare("INSERT INTO manga (title, file_id, description, added_by) VALUES (?, ?, ?, ?)")
                    ->execute(['❤️ ' . $mTitle, $mLink, $mDesc, $chatId]);

                logArchive($pdo, 'add_manga', "Опубликована манга: $mTitle", $chatId);
                sendSimpleMsg(
                    $chatId,
                    "✅ Манга *$mTitle* добавлена в каталог!\n\n_Чтобы добавить обложку — отправьте фото с подписью:_\n`+$mTitle`",
                    $apiUrl
                );
            } else {
                sendSimpleMsg($chatId, "⚠️ *Неверный формат.*\n\nИспользуйте:\n`+Название | ссылка telegra.ph | описание`", $apiUrl);
            }
            exit;
        }

        // Быстрое добавление манги через команду (старый формат — оставлен без изменений)
        if (strpos($text, 'добавить мангу +') === 0) {
            $parts = explode('|', str_replace('добавить мангу +', '', $text));
            if (count($parts) >= 3) {
                $mTitle = trim($parts[0]);
                $mLink  = trim($parts[1]);
                $mDesc  = trim($parts[2]);
                $pdo->prepare("INSERT INTO manga (title, file_id, description, added_by) VALUES (?, ?, ?, ?)")
                    ->execute(['❤️ ' . $mTitle, $mLink, $mDesc, $chatId]);
                logArchive($pdo, 'add_manga', "Опубликована манга: $mTitle", $chatId);
                sendSimpleMsg($chatId, "✅ Манга *$mTitle* успешно добавлена в каталог!", $apiUrl);
            } else {
                sendSimpleMsg($chatId, "⚠️ *Формат:* добавить мангу + Название | Ссылка | Описание", $apiUrl);
            }
            exit;
        }

        if ($text == "➕ Добавить через альбом") {
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_pages', '[]')")->execute([$chatId]);
            sendSimpleMsg($chatId, "🖼 *Шаг 1 из 4:* Отправьте страницы главы *альбомом*.\n\n_Когда загрузите всё — напишите:_ *стоп*", $apiUrl);
            exit;
        }

        // =============================================
        // НОВОЕ: кнопка «Добавить через ZIP»
        // =============================================
        if ($text == "📦 Добавить через ZIP") {
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_zip', '[]')")->execute([$chatId]);
            sendSimpleMsg($chatId,
                "📦 *Шаг 1 из 4:* Отправьте ZIP-архив с фото главы.\n\n" .
                "_Фото будут отсортированы по дате из заголовка архива — в том порядке, в котором вы их скачивали._\n\n" .
                "⚠️ *Ограничение Telegram Bot API:* файлы до 20 МБ.",
                $apiUrl);
            exit;
        }

        // =============================================
        // НОВОЕ: кнопка «✏️ Редактирование»
        // =============================================
        if ($text == "✏️ Редактирование") {
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_edit_search', '[]')")->execute([$chatId]);
            $c = getEditSearchData($pdo, '', 0);
            sendSimpleMsg($chatId,
                "✏️ *Редактирование манги*\n\n_Выберите мангу из списка или напишите название для поиска:_",
                $apiUrl,
                $c['reply_markup']
            );
            exit;
        }

        if ($userState) {
            if ($userState['step'] == 'wait_pages') {
                if (mb_strtolower($text) == 'стоп') {
                    $pdo->prepare("UPDATE temp_data SET step = 'wait_title' WHERE user_id = ?")->execute([$chatId]);
                    sendSimpleMsg($chatId, "✅ Фото загружены!\n\n*Шаг 2 из 4:* Введите *название* манги:", $apiUrl);
                } elseif (isset($message['photo'])) {
                    $pages = json_decode($userState['pages'], true);
                    $pages[] = end($message['photo'])['file_id'];
                    $pdo->prepare("UPDATE temp_data SET pages = ? WHERE user_id = ?")->execute([json_encode($pages), $chatId]);
                }
                exit;
            }
            elseif ($userState['step'] == 'wait_title') {
                $pdo->prepare("UPDATE temp_data SET title = ?, step = 'wait_desc' WHERE user_id = ?")->execute([$text, $chatId]);
                sendSimpleMsg($chatId, "📝 *Шаг 3 из 4:* Введите *описание* манги:", $apiUrl);
                exit;
            }
            elseif ($userState['step'] == 'wait_desc') {
                $pdo->prepare("UPDATE temp_data SET description = ?, step = 'wait_cover' WHERE user_id = ?")->execute([$text, $chatId]);
                sendSimpleMsg($chatId, "🖼 *Шаг 4 из 4:* Отправьте фото, которое станет *обложкой*:", $apiUrl);
                exit;
            }
            elseif ($userState['step'] == 'wait_cover' && isset($message['photo'])) {
                $coverId   = end($message['photo'])['file_id'];
                $pageCount = count(json_decode($userState['pages'], true));
                sendSimpleMsg($chatId, "⏳ _Начинаю генерацию страницы Instant View. Обрабатываю $pageCount фото — подождите..._", $apiUrl);

                $telegraphLink = createTelegraphPage($userState['title'], json_decode($userState['pages'], true), $token, $apiUrl);

                if ($telegraphLink) {
                    $titleWithHeart = '❤️ ' . $userState['title'];
                    $pdo->prepare("INSERT INTO manga (title, file_id, description, cover_id, added_by) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$titleWithHeart, $telegraphLink, $userState['description'], $coverId, $chatId]);
                    logArchive($pdo, 'add_manga', "Опубликована манга: {$userState['title']}", $chatId);
                    sendSimpleMsg($chatId, "🚀 *Успех!* Манга добавлена в каталог.\n\n🔗 Ссылка: $telegraphLink", $apiUrl, $adminKeyboard);
                } else {
                    sendSimpleMsg($chatId, "❌ *Ошибка при работе с Telegraph.* Проверьте логи сервера.", $apiUrl, $adminKeyboard);
                }
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                exit;
            }

            // =============================================
            // ZIP: шаги после загрузки архива
            // =============================================
            elseif ($userState['step'] == 'wait_title_zip') {
                $pdo->prepare("UPDATE temp_data SET title = ?, step = 'wait_desc_zip' WHERE user_id = ?")->execute([$text, $chatId]);
                sendSimpleMsg($chatId, "📝 *Шаг 3 из 4:* Введите *описание* манги:", $apiUrl);
                exit;
            }
            elseif ($userState['step'] == 'wait_desc_zip') {
                $pdo->prepare("UPDATE temp_data SET description = ?, step = 'wait_cover_zip' WHERE user_id = ?")->execute([$text, $chatId]);
                sendSimpleMsg($chatId, "🖼 *Шаг 4 из 4:* Отправьте фото, которое станет *обложкой*:", $apiUrl);
                exit;
            }
            elseif ($userState['step'] == 'wait_cover_zip' && isset($message['photo'])) {
                $coverId   = end($message['photo'])['file_id'];
                $stateData = json_decode($userState['pages'], true);
                $imgUrls   = $stateData['imgbb_urls'] ?? [];

                sendSimpleMsg($chatId, "⏳ _Генерирую Telegraph-страницу из " . count($imgUrls) . " фото..._", $apiUrl);

                // Собираем nodes напрямую из ImgBB URLs (уже загружены ранее)
                $nodes = [];

                // Промо-фото первым слайдом
                $promoUrl = getPromoImageUrl($pdo, $imgbbKey);
                if ($promoUrl) {
                    $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $promoUrl]];
                }

                foreach ($imgUrls as $url) {
                    $nodes[] = ['tag' => 'img', 'attrs' => ['src' => $url]];
                }

                $postData = [
                    'title'          => $userState['title'],
                    'author_name'    => 'Manga Reader',
                    'content'        => json_encode($nodes),
                    'return_content' => true
                ];

                $ch = curl_init("https://api.telegra.ph/createPage?access_token=192627565eb929153713373081fb7dd3eb3701cf4a36a2f9243d3866f831");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $rawResponse = curl_exec($ch);
                curl_close($ch);

                $res           = json_decode($rawResponse, true);
                $telegraphLink = $res['result']['url'] ?? false;

                if ($telegraphLink) {
                    $titleWithHeart = '❤️ ' . $userState['title'];
                    $pdo->prepare("INSERT INTO manga (title, file_id, description, cover_id, added_by) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$titleWithHeart, $telegraphLink, $userState['description'], $coverId, $chatId]);
                    logArchive($pdo, 'add_manga', "Опубликована манга (ZIP): {$userState['title']}", $chatId);
                    sendSimpleMsg($chatId, "🚀 *Успех!* Манга добавлена в каталог.\n\n🔗 Ссылка: $telegraphLink", $apiUrl, $adminKeyboard);
                } else {
                    sendSimpleMsg($chatId, "❌ *Ошибка при создании Telegraph-страницы.*\n\nОтвет: " . $rawResponse, $apiUrl, $adminKeyboard);
                }

                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                exit;
            }
        }

        // Удаление через минус
        if (strpos(trim($text), '-') === 0) {
            $search = trim(ltrim(trim($text), '- '));
            $stmt = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? LIMIT 5");
            $stmt->execute(["%$search%"]);
            $results = $stmt->fetchAll();
            if ($results) {
                $btns = [];
                foreach ($results as $r) {
                    $btns[] = [['text' => '🗑 Удалить: ' . $r['title'], 'callback_data' => 'delete_confirm_' . $r['id']]];
                }
                sendSimpleMsg($chatId, "🔎 *Найдено в базе.* Выберите для удаления:", $apiUrl, json_encode(['inline_keyboard' => $btns]));
            } else {
                sendSimpleMsg($chatId, "❌ Ничего не найдено по запросу *«$search»*", $apiUrl);
            }
            exit;
        }
    }

    // Обработка поиска
    if ($userState && $userState['step'] == 'wait_search') {
        $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
        $query = trim($text);
        $c = getSearchData($pdo, $query, 0);
        sendSimpleMsg($chatId, $c['text'], $apiUrl, $c['reply_markup']);
        exit;
    }

    switch ($text) {
        case "/start":
        case "🔙 Выйти в режим читателя":
            sendSimpleMsg($chatId, "👋 *Добро пожаловать в Manga Reader Bot!*\n\n_Выберите действие в меню ниже:_", $apiUrl, getMainMenu($chatId, $admins));
            break;

        case "⚙️ АДМИН-ПАНЕЛЬ":
            if (in_array($chatId, $admins)) {
                sendSimpleMsg($chatId, "🛡 *Панель управления администратора*\n\n_Выберите нужный раздел:_", $apiUrl, $adminKeyboard);
            }
            break;

        // =============================================
        // СТАТИСТИКА АДМИНОВ — с тегами/именами
        // =============================================
        case "📊 Статистика админов":
            $stmt = $pdo->query("SELECT added_by, COUNT(*) as cnt FROM manga GROUP BY added_by ORDER BY cnt DESC");
            $report = "🏆 *Рейтинг активности администраторов:*\n\n";
            $place = 1;
            while ($row = $stmt->fetch()) {
                $tagName = getAdminTag($pdo, $row['added_by']);
                $medal = match($place) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "  $place." };
                $report .= "$medal *$tagName* — _{$row['cnt']} добавлений_\n";
                $place++;
            }
            sendSimpleMsg($chatId, $report, $apiUrl);
            break;

        case "📥 Читать предложку":
            $stmt = $pdo->query("SELECT id, text FROM suggestions WHERE status = 'new' ORDER BY id DESC LIMIT 10");
            $rows = $stmt->fetchAll();
            if (empty($rows)) {
                sendSimpleMsg($chatId, "📭 *В предложке пока пусто.*\n\n_Новые предложения от пользователей появятся здесь._", $apiUrl);
            } else {
                $btns = [];
                foreach ($rows as $row) {
                    $preview = mb_substr($row['text'], 0, 30);
                    $btns[] = [['text' => "✉️ " . $preview . '...', 'callback_data' => "view_suggest_" . $row['id']]];
                }
                sendSimpleMsg($chatId, "📋 *Новые предложения от пользователей:*\n\n_Нажмите, чтобы открыть:_", $apiUrl, json_encode(['inline_keyboard' => $btns]));
            }
            break;

        // =============================================
        // АРХИВ БОТА
        // =============================================
        case "🗂 Архив бота":
            if (in_array($chatId, $admins)) {
                $archiveData = getArchiveData($pdo, $admins, 0);
                sendSimpleMsg($chatId, $archiveData['text'], $apiUrl, $archiveData['reply_markup']);
            }
            break;

        case "❓ FAQ и Команды":
            if (in_array($chatId, $admins)) {
                $faq  = "📖 *ИНСТРУКЦИЯ АДМИНИСТРАТОРА*\n";
                $faq .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
                $faq .= "📌 *Команды в чат:*\n\n";
                $faq .= "🔹 `+Название | ссылка telegra.ph | описание` — быстрое добавление манги\n";
                $faq .= "🔹 _(фото с подписью)_ `+Название` — привязать обложку к манге\n";
                $faq .= "🔹 `Всем+ Текст` — рассылка всем пользователям\n";
                $faq .= "🔹 `тег+` — управление именами администраторов\n";
                $faq .= "🔹 `+тег` — список ID и имён всех админов\n";
                $faq .= "🔹 `Админ+ ID` — назначить нового админа _(только гл. админы)_\n";
                $faq .= "🔹 `добавить мангу + Название | Ссылка | Описание` — быстрое добавление _(старый формат)_\n";
                $faq .= "🔹 `- Название` — поиск манги для удаления\n\n";
                $faq .= "━━━━━━━━━━━━━━━━━━━━━\n";
                $faq .= "📋 *Обязанности:*\n\n";
                $faq .= "1️⃣ Регулярно проверять раздел предложки.\n";
                $faq .= "2️⃣ Добавлять только качественные ссылки.\n";
                $faq .= "3️⃣ Не злоупотреблять рассылкой `Всем+`.\n";
                $faq .= "4️⃣ Отвечать на предложения пользователей.\n";
                sendSimpleMsg($chatId, $faq, $apiUrl);
            }
            break;

        case "🔍 Найти мангу":
            $c = getSearchData($pdo, '', 0);
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_search', '[]')")->execute([$chatId]);
            sendSimpleMsg($chatId, "📚 *Весь каталог манги:*\n\n_Для поиска по названию — просто напишите его в чат:_", $apiUrl, $c['reply_markup']);
            break;

        case "📚 Моя библиотека":
            sendLibrary($chatId, $pdo, $apiUrl);
            break;

        case "🔥 Топ по лайкам":
            $stmt = $pdo->query("SELECT title, likes FROM manga WHERE likes > 0 ORDER BY likes DESC LIMIT 10");
            $res = $stmt->fetchAll();
            $msg = "🔥 *Самая популярная манга:*\n\n";
            if ($res) {
                foreach ($res as $k => $v) {
                    $msg .= ($k + 1) . ". *{$v['title']}* — 👍 _{$v['likes']} лайков_\n";
                }
            } else {
                $msg .= "_Рейтинг ещё не сформирован._";
            }
            sendSimpleMsg($chatId, $msg, $apiUrl);
            break;

        case "🎲 Случайная манга":
            $stmt = $pdo->prepare("SELECT m.* FROM manga m LEFT JOIN user_manga_status s ON m.id = s.manga_id AND s.user_id = ? WHERE s.status IS NULL OR s.status != 'read' ORDER BY RAND() LIMIT 1");
            $stmt->execute([$chatId]);
            $random = $stmt->fetch();
            if ($random) {
                sendMangaCard($chatId, $random, $apiUrl);
            } else {
                sendSimpleMsg($chatId, "😮 *Невероятно!* Вы прочитали уже всю мангу в нашей базе!", $apiUrl);
            }
            break;

        case "💡 Предложить мангу":
            $pdo->prepare("REPLACE INTO temp_data (user_id, step, pages) VALUES (?, 'wait_suggest', '[]')")->execute([$chatId]);
            sendSimpleMsg($chatId, "💡 *Напишите название или ссылку* на мангу, которую хотите предложить.\n\n_Администраторы рассмотрят ваше предложение в ближайшее время._", $apiUrl);
            break;

        default:
            if ($userState && $userState['step'] == 'wait_suggest' && !empty($text)) {
                $pdo->prepare("INSERT INTO suggestions (user_id, text) VALUES (?, ?)")->execute([$chatId, $text]);
                $pdo->prepare("DELETE FROM temp_data WHERE user_id = ?")->execute([$chatId]);
                sendSimpleMsg($chatId, "🙏 *Спасибо!* Ваше предложение передано администраторам.\n\n_Мы рассмотрим его в ближайшее время._", $apiUrl);

                // Уведомление всем администраторам о новом предложении
                foreach ($admins as $admId) {
                    tgPost($apiUrl . "/sendMessage", [
                        'chat_id'    => $admId,
                        'text'       => "📥 *Новое предложение в очереди!*\n\n_Зайдите в раздел «Читать предложку», чтобы ознакомиться._",
                        'parse_mode' => 'Markdown'
                    ]);
                }
            }
            break;
    }
}

// =============================================
// АРХИВ БОТА — функция получения данных
// =============================================
function getArchiveData($pdo, $admins, $page) {
    $limit  = 10;
    $offset = $page * $limit;
    $total  = $pdo->query("SELECT COUNT(*) FROM bot_archive")->fetchColumn();

    $stmt = $pdo->prepare("SELECT *, DATE_FORMAT(created_at, '%d.%m.%Y %H:%i') as formatted_time FROM bot_archive ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return [
            'text'         => "🗂 *Архив пуст*\n\n_Здесь будут отображаться все действия администраторов._",
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ];
    }

    $typeLabels = [
        'add_manga'    => '📖 Опубликована манга',
        'delete_manga' => '🗑 Удалена манга',
        'edit_manga'   => '✏️ Изменена манга',
        'broadcast'    => '📢 Сообщение всем',
        'new_admin'    => '👤 Назначен администратор',
        'set_tag'      => '🏷 Установлена метка',
        'set_cover'    => '🖼 Установлена обложка',
    ];

    $text = "🗂 *АРХИВ ДЕЙСТВИЙ БОТА*\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━━\n\n";

    foreach ($rows as $row) {
        $label   = $typeLabels[$row['action_type']] ?? '📝 Действие';
        $tagName = getAdminTag($pdo, $row['action_by']);
        $time    = $row['formatted_time'] ?? $row['created_at'];
        $text .= "$label\n";
        $text .= "👤 $tagName  •  🕐 $time\n";
        $text .= "💬 {$row['action_text']}\n";
        $text .= "─────────────────────\n";
    }

    $nav = [];
    if ($page > 0) {
        $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'archive_page_' . ($page - 1)];
    }
    $totalPages = ceil($total / $limit);
    if ($totalPages > 1) {
        $nav[] = ['text' => ($page + 1) . " / $totalPages", 'callback_data' => 'none'];
    }
    if (($offset + $limit) < $total) {
        $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'archive_page_' . ($page + 1)];
    }

    $btns = [];
    if (!empty($nav)) $btns[] = $nav;

    return [
        'text'         => $text,
        'reply_markup' => json_encode(['inline_keyboard' => $btns])
    ];
}

function getSearchData($pdo, $query, $page) {
    $limit  = 5;
    $offset = $page * $limit;
    $q      = trim($query);

    if ($q === '') {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt  = $pdo->prepare("SELECT id, title FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $headerText = "📖 *Каталог доступной манги:*";
    } else {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $totalStmt->execute(["%$q%"]);
        $total = $totalStmt->fetchColumn();
        $stmt  = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
        $headerText = "🔎 Результаты по запросу *«$q»*:";
    }

    $list = $stmt->fetchAll();

    if (empty($list)) {
        return [
            'text'         => "😔 *Ничего не найдено.*\n\n_Попробуйте другое название или просмотрите полный каталог._",
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ];
    }

    $btns = [];
    foreach ($list as $m) {
        $btns[] = [['text' => "📘 " . $m['title'], 'callback_data' => 'show_' . $m['id']]];
    }

    $nav = [];
    if ($page > 0) {
        $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'search_page_' . urlencode($q) . '_' . ($page - 1)];
    }

    $totalPages = ceil($total / $limit);
    if ($totalPages > 1) {
        $nav[] = ['text' => ($page + 1) . " / " . $totalPages, 'callback_data' => 'none'];
    }

    if (($offset + $limit) < $total) {
        $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'search_page_' . urlencode($q) . '_' . ($page + 1)];
    }

    if (!empty($nav)) $btns[] = $nav;

    return [
        'text'         => $headerText . "\n_Выберите произведение из списка:_",
        'reply_markup' => json_encode(['inline_keyboard' => $btns])
    ];
}

// =============================================
// НОВОЕ: Поиск для режима редактирования
// =============================================
function getEditSearchData($pdo, $query, $page) {
    $limit  = 5;
    $offset = $page * $limit;
    $q      = trim($query);

    if ($q === '') {
        $total = $pdo->query("SELECT COUNT(*) FROM manga")->fetchColumn();
        $stmt  = $pdo->prepare("SELECT id, title FROM manga ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $headerText = "✏️ *Редактирование — весь каталог:*";
    } else {
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM manga WHERE title LIKE ?");
        $totalStmt->execute(["%$q%"]);
        $total = $totalStmt->fetchColumn();
        $stmt  = $pdo->prepare("SELECT id, title FROM manga WHERE title LIKE ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute(["%$q%"]);
        $headerText = "🔎 Результаты по запросу *«$q»*:";
    }

    $list = $stmt->fetchAll();

    if (empty($list)) {
        return [
            'text'         => "😔 *Ничего не найдено.*\n\n_Попробуйте другое название._",
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ];
    }

    $btns = [];
    foreach ($list as $m) {
        $btns[] = [['text' => "✏️ " . $m['title'], 'callback_data' => 'edit_manga_' . $m['id']]];
    }

    $nav = [];
    if ($page > 0) {
        $nav[] = ['text' => '⬅️ Назад', 'callback_data' => 'edit_page_' . urlencode($q) . '_' . ($page - 1)];
    }

    $totalPages = ceil($total / $limit);
    if ($totalPages > 1) {
        $nav[] = ['text' => ($page + 1) . " / " . $totalPages, 'callback_data' => 'none'];
    }

    if (($offset + $limit) < $total) {
        $nav[] = ['text' => 'Вперёд ➡️', 'callback_data' => 'edit_page_' . urlencode($q) . '_' . ($page + 1)];
    }

    if (!empty($nav)) $btns[] = $nav;

    return [
        'text'         => $headerText . "\n_Выберите мангу для редактирования:_",
        'reply_markup' => json_encode(['inline_keyboard' => $btns])
    ];
}

// =============================================
// НОВОЕ: Меню редактирования — новое сообщение
// =============================================
function sendEditMangaMenu($chatId, $m, $apiUrl) {
    $mId  = $m['id'];
    $desc = mb_substr(strip_tags($m['description'] ?? ''), 0, 80);
    $text = "✏️ *Редактирование манги*\n\n";
    $text .= "📖 *Название:* {$m['title']}\n";
    $text .= "📝 *Описание:* _{$desc}..._\n";
    $text .= "🔗 *Ссылка:* {$m['file_id']}\n";
    $text .= "🖼 *Обложка:* " . (!empty($m['cover_id']) ? "✅ Есть" : "❌ Нет") . "\n\n";
    $text .= "_Выберите что изменить:_";

    $kb = ['inline_keyboard' => [
        [
            ['text' => '🖼 Изменить обложку',  'callback_data' => 'editfield_cover_'  . $mId],
            ['text' => '📖 Изменить название', 'callback_data' => 'editfield_title_'  . $mId],
        ],
        [
            ['text' => '📝 Изменить описание', 'callback_data' => 'editfield_desc_'   . $mId],
            ['text' => '🔗 Изменить ссылку',   'callback_data' => 'editfield_link_'   . $mId],
        ],
        [
            ['text' => '🗑 Удалить мангу', 'callback_data' => 'delete_confirm_' . $mId],
        ]
    ]];

    if (!empty($m['cover_id'])) {
        tgPost($apiUrl . "/sendPhoto", [
            'chat_id'      => $chatId,
            'photo'        => $m['cover_id'],
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $kb
        ]);
    } else {
        tgPost($apiUrl . "/sendMessage", [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $kb
        ]);
    }
}

// =============================================
// НОВОЕ: Меню редактирования — редактирование существующего сообщения
// =============================================
function sendEditMangaMenuEdit($chatId, $msgId, $m, $apiUrl) {
    $mId  = $m['id'];
    $desc = mb_substr(strip_tags($m['description'] ?? ''), 0, 80);
    $text = "✏️ *Редактирование манги*\n\n";
    $text .= "📖 *Название:* {$m['title']}\n";
    $text .= "📝 *Описание:* _{$desc}..._\n";
    $text .= "🔗 *Ссылка:* {$m['file_id']}\n";
    $text .= "🖼 *Обложка:* " . (!empty($m['cover_id']) ? "✅ Есть" : "❌ Нет") . "\n\n";
    $text .= "_Выберите что изменить:_";

    $kb = ['inline_keyboard' => [
        [
            ['text' => '🖼 Изменить обложку',  'callback_data' => 'editfield_cover_'  . $mId],
            ['text' => '📖 Изменить название', 'callback_data' => 'editfield_title_'  . $mId],
        ],
        [
            ['text' => '📝 Изменить описание', 'callback_data' => 'editfield_desc_'   . $mId],
            ['text' => '🔗 Изменить ссылку',   'callback_data' => 'editfield_link_'   . $mId],
        ],
        [
            ['text' => '🗑 Удалить мангу', 'callback_data' => 'delete_confirm_' . $mId],
        ]
    ]];

    $method = !empty($m['cover_id']) ? "editMessageCaption" : "editMessageText";
    $param  = !empty($m['cover_id']) ? "caption" : "text";

    tgPost($apiUrl . "/$method", [
        'chat_id'      => $chatId,
        'message_id'   => $msgId,
        $param         => $text,
        'parse_mode'   => 'Markdown',
        'reply_markup' => $kb
    ]);
}

function sendMangaCard($chatId, $m, $apiUrl) {
    if (!$m) return;
    $text = "📖 *" . $m['title'] . "*\n\n" . $m['description'] . "\n\n━━━━━━━━━━━━━━━━━\n👍 _{$m['likes']} лайков_  |  👎 _{$m['dislikes']} дизлайков_";
    $kb = ['inline_keyboard' => [
        [['text' => '🔗 ЧИТАТЬ ГЛАВУ', 'url' => $m['file_id']]],
        [['text' => '⏳ Читаю сейчас', 'callback_data' => 'stat_now_' . $m['id']], ['text' => '✅ Прочитано', 'callback_data' => 'stat_read_' . $m['id']]],
        [['text' => '👍 Лайк', 'callback_data' => 'vote_like_' . $m['id']], ['text' => '👎 Дизлайк', 'callback_data' => 'vote_dislike_' . $m['id']]]
    ]];

    if (!empty($m['cover_id'])) {
        tgPost($apiUrl . "/sendPhoto", [
            'chat_id'      => $chatId,
            'photo'        => $m['cover_id'],
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $kb
        ]);
    } else {
        tgPost($apiUrl . "/sendMessage", [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $kb
        ]);
    }
}

function updateMangaMessage($chatId, $msgId, $m, $apiUrl) {
    $text = "📖 *" . $m['title'] . "*\n\n" . $m['description'] . "\n\n━━━━━━━━━━━━━━━━━\n👍 _{$m['likes']} лайков_  |  👎 _{$m['дислайков']} дизлайков_";
    $kb = ['inline_keyboard' => [
        [['text' => '🔗 ЧИТАТЬ ГЛАВУ', 'url' => $m['file_id']]],
        [['text' => '⏳ Читаю сейчас', 'callback_data' => 'stat_now_' . $m['id']], ['text' => '✅ Прочитано', 'callback_data' => 'stat_read_' . $m['id']]],
        [['text' => '👍 Лайк', 'callback_data' => 'vote_like_' . $m['id']], ['text' => '👎 Дизлайк', 'callback_data' => 'vote_dislike_' . $m['id']]]
    ]];

    $method = !empty($m['cover_id']) ? "editMessageCaption" : "editMessageText";
    $param  = !empty($m['cover_id']) ? "caption" : "text";

    tgPost($apiUrl . "/$method", [
        'chat_id'      => $chatId,
        'message_id'   => $msgId,
        $param         => $text,
        'parse_mode'   => 'Markdown',
        'reply_markup' => $kb
    ]);
}

function sendLibrary($chatId, $pdo, $apiUrl) {
    $stmt = $pdo->prepare("
        SELECT m.title, s.status 
        FROM user_manga_status s 
        JOIN manga m ON s.manga_id = m.id 
        WHERE s.user_id = ?
    ");
    $stmt->execute([$chatId]);
    $res = $stmt->fetchAll();

    $now = []; $read = [];
    foreach ($res as $i) {
        if ($i['status'] == 'now') $now[] = "🔹 " . $i['title'];
        else $read[] = "✅ " . $i['title'];
    }

    $msg  = "📚 *ТВОЯ ЛИЧНАЯ БИБЛИОТЕКА*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━\n\n";
    $msg .= "⏳ *Сейчас читаю:*\n" . ($now ? implode("\n", $now) : "_Пока ничего_") . "\n\n";
    $msg .= "🏆 *Прочитано:*\n"    . ($read ? implode("\n", $read) : "_Список пуст_");

    sendSimpleMsg($chatId, $msg, $apiUrl);
}

function sendSimpleMsg($chatId, $text, $apiUrl, $kb = null) {
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($kb) {
        $data['reply_markup'] = is_string($kb) ? json_decode($kb) : $kb;
    }
    return tgPost($apiUrl . "/sendMessage", $data);
}