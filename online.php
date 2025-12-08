<?php
// Обработчик счетчика онлайн пользователей

// Настройки
$data_file = 'online.txt';    // Файл для хранения данных
$timeout = 300;               // 5 минут неактивности (в секундах)

// Разрешаем запросы с любого источника (CORS)
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Функция для логирования (для отладки)
function log_debug($message) {
    $log_file = 'online_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    file_put_contents($log_file, "[$timestamp][$ip] $message\n", FILE_APPEND);
}

// Получаем идентификатор пользователя
function get_user_id() {
    // 1. Пробуем получить из GET-параметра
    if (!empty($_GET['session_id'])) {
        return trim($_GET['session_id']);
    }
    
    // 2. Пробуем получить из POST-данных
    if (!empty($_POST['session_id'])) {
        return trim($_POST['session_id']);
    }
    
    // 3. Создаем на основе IP и User-Agent
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    return md5($ip . $user_agent . date('Y-m-d H'));
}

// Чтение данных из файла
function read_online_data($filename) {
    if (!file_exists($filename)) {
        // Создаем файл если его нет
        file_put_contents($filename, serialize([]));
        chmod($filename, 0666);
        return [];
    }
    
    $content = @file_get_contents($filename);
    if ($content === false || empty($content)) {
        return [];
    }
    
    $data = @unserialize($content);
    if ($data === false) {
        // Файл поврежден, создаем новый
        file_put_contents($filename, serialize([]));
        return [];
    }
    
    return $data;
}

// Запись данных в файл
function write_online_data($filename, $data) {
    // Сортируем по времени (новые в начале)
    arsort($data);
    
    // Сохраняем
    $result = @file_put_contents($filename, serialize($data));
    if ($result === false) {
        log_debug("Ошибка записи в файл $filename");
        return false;
    }
    
    return true;
}

// Очистка устаревших записей
function cleanup_old_entries(&$data, $timeout) {
    $current_time = time();
    $removed_count = 0;
    
    foreach ($data as $user_id => $last_activity) {
        if ($current_time - $last_activity > $timeout) {
            unset($data[$user_id]);
            $removed_count++;
        }
    }
    
    return $removed_count;
}


// ОСНОВНОЙ КОД

try {
    // Получаем действие
    $action = $_GET['action'] ?? $_POST['action'] ?? 'update';
    $user_id = get_user_id();
    
    // Читаем текущие данные
    $online_data = read_online_data($data_file);
    
    // Обрабатываем разные действия
    switch ($action) {
        case 'update':
            // Очищаем устаревшие записи
            cleanup_old_entries($online_data, $timeout);
            
            // Добавляем/обновляем текущего пользователя
            $online_data[$user_id] = time();
            
            // Сохраняем данные
            write_online_data($data_file, $online_data);
            
            // Возвращаем количество онлайн
            echo count($online_data);
            break;
            
        case 'leave':
            // Удаляем пользователя при уходе
            if (isset($online_data[$user_id])) {
                unset($online_data[$user_id]);
                write_online_data($data_file, $online_data);
            }
            echo 'OK';
            break;
            
        case 'get':
            // Просто получаем количество без обновления
            cleanup_old_entries($online_data, $timeout);
            write_online_data($data_file, $online_data);
            echo count($online_data);
            break;
            
        case 'clear':
            // Очистка всех данных (для админа)
            $online_data = [];
            write_online_data($data_file, $online_data);
            echo 'CLEARED';
            break;
            
        case 'info':
            // Подробная информация (для отладки)
            cleanup_old_entries($online_data, $timeout);
            $result = [
                'count' => count($online_data),
                'users' => array_map(function($time) {
                    return [
                        'last_active' => date('H:i:s', $time),
                        'inactive_sec' => time() - $time
                    ];
                }, $online_data),
                'timestamp' => time()
            ];
            header('Content-Type: application/json');
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo '0';
    }
    
} catch (Exception $e) {
    // В случае ошибки возвращаем 0
    log_debug("Ошибка: " . $e->getMessage());
    echo '0';
}

// Закрываем соединение для быстрого ответа
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
?>