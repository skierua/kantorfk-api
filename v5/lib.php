<?php
define("API_VERSION", "v5");
define("PROJECT_ROOT_PATH", __DIR__ . "/");
define("LOGS_PATH",  __DIR__ . "../../../../../log-app/");
define("PATH_TO_MEMCACHED", "/home/g32062/.memcached/memcached.sock"); // for deployment
// define("PATH_TO_MEMCACHED", "");        // for local testing
define("MEMCACHED_TTL", 8);
define("DFLT_TRADE_ACNT", "3500");
// echo "lib.php path=".PROJECT_ROOT_PATH."\n";
require_once PROJECT_ROOT_PATH . "DotEnvLoader.php";

// $gl_lastQuery = "";
// $gl_payload = ""; // string not JSON !!!
$global_db_connection = null;
$global_payload = null;

function dbconnect(){
    // Чисті та безпечні параметри з файлу .env
    $host = $_ENV['DB_HOST'].":".$_ENV['DB_PORT'] ?? 'localhost';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASSWORD'] ?? '';
    $name = $_ENV['DB_NAME'] ?? 'db';

    $conn = new mysqli($host, $user, $pass, $name);
    
    if ($conn->connect_error) {
        return null;
    }
    
    $conn->set_charset("utf8mb4"); // Захист кодування для емодзі-прапорців
    return $conn;

    // $db1 = new mysqli($_ENV['DB_HOST'].":".$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME']);
    // $db1->set_charset("utf8");
    // return $db1;
}

/**
 * Скидає кеш Memcached для конкретного SQL-запиту
 * @param string $vquery Текст SELECT запиту, кеш якого треба видалити
 * @return bool Результат видалення
 */
function sqlCache_clear($vquery) {
    $memcached_path = defined('PATH_TO_MEMCACHED') ? PATH_TO_MEMCACHED : "";
    
    if (!empty($memcached_path) && class_exists("Memcached")) {
        $mem = new Memcached();
        $mem->addServer($memcached_path, 11211);
        
        // Генеруємо ТОЧНО такий самий ключ, як і при збереженні
        $key = "KEY" . md5($vquery);
        
        // Видаляємо ключ із пам'яті Memcached
        return $mem->delete($key);
    }
    return false;
}

// ttl < 0 default TTL, ttl = 0 no cache, ttl > 0 cache for ttl seconds
function sqlExec($vsql, $ttl = -1, $params = []) {
    $dfltTtl = (defined('MEMCACHED_TTL') ? MEMCACHED_TTL : 8);
    $ttlVal = (is_int($ttl) && $ttl < 0) ? $dfltTtl : $ttl;
    $resp = [];
    $mem = null;
    $key = "";

    // 1. Робота з кешем (Читання)
    if ($ttlVal > 0) {
        $memcached_path = defined('PATH_TO_MEMCACHED') ? PATH_TO_MEMCACHED : "";

        if (!empty($memcached_path) && class_exists("Memcached")) {
            $mem = new Memcached("sql_cache_pool");
            if (empty($mem->getServerList())) {
                $mem->addServer($memcached_path, 11211);
            }
            
            $key = "KEY" . md5($vsql . json_encode($params));
            $cachedResp = $mem->get($key);

            if ($mem->getResultCode() !== Memcached::RES_NOTFOUND) {
                // Кеш знайдено — повертаємо структуру
                return $cachedResp;
            }
        }
    }

    // 2. Перевірка підключення до БД
    global $global_db_connection;

    if ($global_db_connection === null) {
        $global_db_connection = dbconnect();
        if ($global_db_connection->connect_errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return [
                'status' => $global_db_connection->connect_errno, 
                'str' => $global_db_connection->connect_error, 
                'note' => ($_ENV['DB_USER'] ?? '') . "@" . ($_ENV['DB_HOST'] ?? ''), 
                'rslt' => $resp
            ];
        }
        $global_db_connection->set_charset("utf8mb4");
    }
    $db = $global_db_connection;
    $trimed = trim($vsql);

    if ($trimed === "") {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Page not found');
        return ['status' => 1, 'str' => 'Page not found', 'note' => $vsql, 'rslt' => $resp];
    }

    // 3. Виконання запиту (з Try-Catch для PHP 8.3)
    try {
        $rslt = $db->execute_query($vsql, $params);
    } catch (\mysqli_sql_exception $e) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return ['status' => $e->getCode(), 'str' => $e->getMessage(), 'note' => $vsql, 'rslt' => $resp];
    }

    // Обробка результату для SELECT запитів
    if ($rslt instanceof \mysqli_result) {
        while ($row = $rslt->fetch_assoc()) {
            $resp[] = $row;
        }
    }

    // Формуємо фінальну відповідь
    if (count($resp) > 0) {
        $finalResult = ['status' => 0, 'str' => 'ok', 'note' => '', 'rslt' => $resp];
    } else {
        // Фікс: Замінено неіснуючу $tquery на $vsql
        $finalResult = ['status' => 0, 'str' => 'Blank result', 'note' => $vsql, 'rslt' => $resp];
    }

    // 4. Робота з кешем (Запис)
    // Зберігаємо в кеш тільки якщо запит успішний і Memcached ініціалізовано
    if ($ttlVal > 0 && $mem !== null && !empty($key)) {
        $mem->set($key, $finalResult, $ttlVal);
    }

    return $finalResult;
}

function sqlReq_cache($vquery, $ttl = -1) {
    $dfltTtl = (defined('MEMCACHED_TTL') ? MEMCACHED_TTL : 8);
    $ttlVal = (is_int($ttl) && $ttl < 0) ? $dfltTtl : $ttl;
    
    if ($ttlVal > 0) {
        $memcached_path = defined('PATH_TO_MEMCACHED') ? PATH_TO_MEMCACHED : "";

        if (!empty($memcached_path) && class_exists("Memcached")) {
            // Fix: Use persistent connection ID to prevent connection leaks
            $mem = new Memcached("sql_cache_pool");
            
            // Only add server if it hasn't been added to this persistent pool yet
            if (empty($mem->getServerList())) {
                $mem->addServer($memcached_path, 11211);
            }
            
            $key = "KEY" . md5($vquery);
            $resp = $mem->get($key);

            // Fix: Explicitly check if the key was missing from cache
            if ($mem->getResultCode() === Memcached::RES_NOTFOUND) {
                $resp = sqlReq($vquery);
                
                // Ensure array structures exist to prevent warnings
                $resp['status'] = $resp['status'] ?? -1;
                $resp['note'] = $resp['note'] ?? "";
                $resp['str'] = $resp['str'] ?? "";

                if ($resp['status'] === 0) {
                    // Fix: Use $ttlVal here instead of the hardcoded $memcached_ttl
                    if (!($mem->set($key, $resp, $ttlVal))) {
                        $resp['note'] .= ": Memcached SET ERROR " . $mem->getResultCode() . "\n";
                    }
                }
                
                $resp['str'] .= " | DataBase Zkey=$key\n";
                return $resp;
            } else {
                // Cache hit
                $resp['str'] = ($resp['str'] ?? "") . " | Memcached Ykey=$key\n";
                return $resp;
            }
        }
    }
    return sqlReq($vquery);
}

function sqlReq($vquery) {
    global $global_db_connection;
    $resp = [];

    // 1. Оптимізація: Перевикористовуємо підключення, якщо воно вже створено
    if ($global_db_connection === null) {
        // $port = $_ENV['DB_PORT'] ?? '3306';
        // $global_db_connection = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME'], $port);
        $global_db_connection = dbconnect();
        if ($global_db_connection->connect_errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return [
                'status' => $global_db_connection->connect_errno, 
                'str' => $global_db_connection->connect_error, 
                'note' => ($_ENV['DB_USER'] ?? '') . "@" . ($_ENV['DB_HOST'] ?? ''), 
                'rslt' => $resp
            ];
        }
        $global_db_connection->set_charset("utf8mb4"); // Надійна підтримка емодзі
    }

    $db = $global_db_connection;
    $tquery = trim($vquery);

    if ($tquery === "") {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Page not found');
        return ['status' => 1, 'str' => 'Page not found', 'note' => $vquery, 'rslt' => $resp];
    }
    // return ['status' => 0, 'str' => 'ok', 'note' => $tquery, 'rslt' => $resp];

    $rslt = $db->query($tquery);
    $err = (int)$db->errno;

    if (!$err) {
        // Перевіряємо, чи це SELECT запит
        if (stripos($tquery, 'select') === 0) {
            // 2. Оптимізація: fetch_assoc() замінює величезний цикл for
            while ($row = $rslt->fetch_assoc()) {
                $resp[] = $row;
            }
        }

        if (count($resp) > 0) {
             return ['status' => 0, 'str' => 'ok', 'note' => '', 'rslt' => $resp];
       } else {
            return ['status' => 0, 'str' => 'Blank result', 'note' => $tquery, 'rslt' => $resp];
        }
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return ['status' => $err, 'str' => $db->error, 'note' => $tquery, 'rslt' => $resp];
    }
}

function sqlAddBind($jsobj, $shopFromPost = '') {
    global $global_db_connection;
    global $global_payload;
    $resp = [];

    // 1. ОПТИМІЗАЦІЯ: Перевикористовуємо глобальне підключення до БД
    if ($global_db_connection === null) {
        $port = $_ENV['DB_PORT'] ?? '3306';
        $global_db_connection = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME'], $port);
        if ($global_db_connection->connect_errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return ['status' => $global_db_connection->connect_errno, 'str' => $global_db_connection->connect_error, 'rslt' => $resp];
        }
        $global_db_connection->set_charset("utf8mb4");
    }
    $db = $global_db_connection;

    // Вмикаємо транзакцію для безпеки фінансових даних
    $db->begin_transaction();

    // // Безпечне отримання часу
    // $bindtm = date('Y-m-d H:i:s');
    // if (isset($jsobj->tm) && !empty($jsobj->tm)) {
    //     $bindtm = $db->real_escape_string($jsobj->tm);
    // }
 
    // Замість $post->shop використовуємо параметр $shopFromPost, який передамо з роутера
    $s_dcm    = $db->real_escape_string($jsobj->dcm ?? "");
    $s_dbt    = $db->real_escape_string($jsobj->dbt ?? "");
    $s_clnt   = $db->real_escape_string($jsobj->clnt ?? "");
    $s_note   = $db->real_escape_string($jsobj->note ?? "");
    $s_cshr   = $db->real_escape_string($jsobj->cshr ?? "");
    $s_shop   = $db->real_escape_string($shopFromPost ?? "NA");
    // $s_shop   = $db->real_escape_string($global_payload->term ?? "BULK");

    // Перетворюємо числа на безпечний формат float/int, щоб запит не падав через порожні рядки
    $n_amnt   = (float)($jsobj->amnt ?? 0);
    $n_eq     = (float)($jsobj->eq ?? 0);
    $n_dsc    = (float)($jsobj->dsc ?? 0);
    $n_bns    = (float)($jsobj->bns ?? 0);

    $vquery = "INSERT INTO vk_dcmbind (dcmcode, dbt, amnt, eq, dsc, bns, clientid, note, shop, cshr) "
            . "VALUES ('$s_dcm', '$s_dbt', $n_amnt, $n_eq, $n_dsc, $n_bns, '$s_clnt', '$s_note', '$s_shop', '$s_cshr');";

    // return ['status' => 0, 'str' => 'ok', 'note' => $vquery, 'rslt' => $resp];

    if (!$db->query($vquery)) {
        $db->rollback(); // Скасовуємо зміни, якщо запит впав
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return ['status' => (int)$db->errno, 'str' => $db->error, 'note' => $vquery, 'rslt' => $resp];
    }

    // Отримуємо ID щойно створеного документа
    $lid = (int)$db->insert_id;

    if ($lid > 0 && isset($jsobj->dcms) && is_array($jsobj->dcms)) {
        $vqval = "";
        
        foreach ($jsobj->dcms as $dcm) {
            $sd_dcm  = $db->real_escape_string($dcm->dcm ?? "");
            $sd_crn  = $db->real_escape_string($dcm->crn ?? "");
            $sd_dbt  = $db->real_escape_string($dcm->dbt ?? "");
            $sd_cdt  = $db->real_escape_string($dcm->cdt ?? "");
            $nd_amnt = (float)($dcm->amnt ?? 0);
            $nd_eq   = (float)($dcm->eq ?? 0);
            $nd_dsc  = (float)($dcm->dsc ?? 0);
            $nd_bns  = (float)($dcm->bns ?? 0);
            $sd_note = $db->real_escape_string($dcm->note ?? "");

            $vqval .= ($vqval !== "" ? "," : "") 
                . " ($lid, '$sd_dcm', '$sd_crn', '$sd_dbt', '$sd_cdt', $nd_amnt, $nd_eq, $nd_dsc, $nd_bns, '$sd_note')";
        }

        if ($vqval !== "") {
            $vquery2 = "INSERT INTO vk_dcm (pid, dcmcode, atclcode, dbt, cdt, amnt, eq, dsc, bns, note) VALUES $vqval ;";
            
            if (!$db->query($vquery2)) {
                $db->rollback(); // Скасовуємо все, якщо дочірні рядки не вставилися
                header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
                return ['status' => (int)$db->errno, 'str' => $db->error, 'note' => $vquery2, 'rslt' => $resp];
            }
        }

        // Якщо обидва запити пройшли успішно — фіксуємо транзакцію в базі
        $db->commit();
        
        // Очищаємо кеш Memcached для балансів, бо касовий ордер міняє залишки на рахунках
        // if (function_exists('sqlCache_clear')) {
        //     sqlCache_clear(QUERY_BALANCE_SSE);
        // }

        return ['status' => 0, 'str' => 'ok', 'note' => '', 'rslt' => ['id' => $lid]];
    } else {
        $db->rollback();
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return ['status' => 1, 'str' => 'Transaction failed: sub-documents missing', 'note' => '', 'rslt' => $resp];
    }
}

function sqlUpdMonRepo($jsobj, $repoid) {
    global $global_db_connection;
    $resp = [];

    if ($global_db_connection === null) {
        $port = $_ENV['DB_PORT'] ?? '3306';
        $global_db_connection = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME'], $port);
        if ($global_db_connection->connect_errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return [
                'status' => $global_db_connection->connect_errno, 
                'str' => $global_db_connection->connect_error, 
                'note' => ($_ENV['DB_USER'] ?? '') . "@" . ($_ENV['DB_HOST'] ?? ''), 
                'rslt' => $resp
            ];
        }
        $global_db_connection->set_charset("utf8mb4");
    }
    
    $db = $global_db_connection;
    
    // Вмикаємо фінансову транзакцію для безпеки пакетного оновлення
    $db->begin_transaction();

    $respnote = "";

    $s_shop      = $db->real_escape_string($jsobj->shop ?? "");
    $s_period    = $db->real_escape_string($jsobj->period ?? "");

    $sql = "delete from vk_mnrslt2 where period='".$s_period."' and shop='".$s_shop."' and code='".$repoid."';";
    $db->query($sql);

    $vqval = "";
    $rows_list = $jsobj->data ?? $jsobj['data'] ?? [];

    foreach ($rows_list as $row) { 
        $s_atclcode  = $db->real_escape_string($row->itemid ?? "");
        $s_acnt = $db->real_escape_string($row->acnt ?? "");
        $s_cshr = $db->real_escape_string($row->cshr ?? "");
        $n_amnt   = (float)($row->amnt ?? 0);
    
        $vqval .= ($vqval !== "" ? "," : "") 
                . " ('$s_period', '$s_atclcode',  $n_amnt, '$s_shop','$repoid', '$s_acnt', '$s_cshr')";

    }
    if ($vqval !== "") {
        $sql = "INSERT INTO vk_mnrslt2 (period, atclcode, amnt, shop, code, acnt, cashier) VALUES $vqval ;";
        if (!$db->query($sql)) {
            $db->rollback(); // Скасовуємо все, якщо дочірні рядки не вставилися
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return ['status' => (int)$db->errno, 'str' => $db->error, 'note' => $sql, 'rslt' => $resp];
        }
    }

        // Якщо обидва запити пройшли успішно — фіксуємо транзакцію в базі
    $db->commit();
    return ['status' => 0, 'str' => '', 'note' => '', 'rslt' => []];
}

function sqlUpdRate($jsobj) {
    global $global_db_connection;
    $resp = [];

    if ($global_db_connection === null) {
        $port = $_ENV['DB_PORT'] ?? '3306';
        $global_db_connection = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME'], $port);
        if ($global_db_connection->connect_errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return [
                'status' => $global_db_connection->connect_errno, 
                'str' => $global_db_connection->connect_error, 
                'note' => ($_ENV['DB_USER'] ?? '') . "@" . ($_ENV['DB_HOST'] ?? ''), 
                'rslt' => $resp
            ];
        }
        $global_db_connection->set_charset("utf8mb4");
    }
    
    $db = $global_db_connection;
    
    // Вмикаємо фінансову транзакцію для безпеки пакетного оновлення
    $db->begin_transaction();

    $ok = true;
    $respnote = "";

    foreach ($jsobj as $rate) {
        $s_shop      = $db->real_escape_string($rate->shop ?? "");
        $s_atclcode  = $db->real_escape_string($rate->atclcode ?? "");
        $s_scode     = $db->real_escape_string($rate->scode ?? "");
        $s_pricecode = $db->real_escape_string($rate->pricecode ?? "");

        $whereclause = "WHERE shop='$s_shop' and atclcode='$s_atclcode' and scode='$s_scode' and pricecode='$s_pricecode'";

        // Захищаємо числові значення від збоїв (замінюємо кому на крапку, якщо касир помилився)
        $bid_val = str_replace(',', '.', $rate->bid ?? '0');
        $ask_val = str_replace(',', '.', $rate->ask ?? '0');
        
        $f_bid = floatval($bid_val);
        $f_ask = floatval($ask_val);

        if ($f_bid === 0.0 && $f_ask === 0.0) {
            $delsql = "DELETE FROM vk_rate $whereclause;";
            $db->query($delsql);
            continue;
        }

        // Безпечний час
        $tm = date('Y-m-d H:i:s');
        if (isset($rate->tm) && !empty($rate->tm)) {
            $tm = $db->real_escape_string($rate->tm);
        }

        $s_bid = $db->real_escape_string($bid_val);
        $s_ask = $db->real_escape_string($ask_val);
        $s_qty = $db->real_escape_string(!empty($rate->qty) ? $rate->qty : "1");

        // Перевіряємо наявність старого курсу для збереження історії у "lastbid/lastask"
        $selsql = "SELECT bid, bidtm, ask, asktm FROM vk_rate $whereclause;";
        $rslt = $db->query($selsql);
        
        $updsqllast = "";
        $newrate = true;

        if ($rslt && $row = $rslt->fetch_assoc()) {
            $newrate = false;
            
            if ($s_bid !== $row['bid']) {
                $updsqllast .= "lastbid='" . $db->real_escape_string($row['bid']) . "', lastbidtm='" . $db->real_escape_string($row['bidtm']) . "'";
            }
            if ($s_ask !== $row['ask']) {
                $updsqllast .= ($updsqllast !== '' ? ", " : "") . "lastask='" . $db->real_escape_string($row['ask']) . "', lastasktm='" . $db->real_escape_string($row['asktm']) . "'";
            }
            
            if ($updsqllast !== "") {
                $db->query("UPDATE vk_rate SET $updsqllast $whereclause;"); 
            }
        }

        // Формуємо фінальний запит запису
        if ($newrate) {
            $updsql = "INSERT INTO vk_rate (shop, atclcode, scode, pricecode, qty, bid, ask, bidtm, asktm) VALUES "
                    . "('$s_shop', '$s_atclcode', '$s_scode', '$s_pricecode', '$s_qty', '$s_bid', '$s_ask', '$tm', '$tm');";
        } else {
            $updsql = "UPDATE vk_rate SET bid='$s_bid', bidtm='$tm', ask='$s_ask', asktm='$tm' $whereclause;";
        }

        if (!$db->query($updsql)) {
            $ok = false;
            $respnote .= " [SQL Error on $s_atclcode: " . $db->error . "] ";
        }
    }

    if ($ok) {
        // Фіксуємо зміни в базі тільки якщо ВСІ курси з масиву оновилися без помилок
        $db->commit();
        return ['status' => 0, 'str' => '', 'note' => '', 'rslt' => []];
    } else {
        // Якщо хоча б один запит зламався — повністю скасовуємо весь пакет, захищаючи логіку даних
        $db->rollback();
        return ['status' => 2, 'str' => 'Batch update error', 'note' => $respnote, 'rslt' => []];
    }
}

function sendTelegramMessage($message) {
    $botToken = $_ENV['TG_BOT_TOKEN'] ?? getenv('TG_BOT_TOKEN');
    $chatId = $_ENV['TG_CHAT_ID'] ?? getenv('TG_CHAT_ID');

    if (!$botToken || !$chatId) {
        return array('status' => 1, 'str' => 'Config error', 'note' => 'Token or ChatID missing', 'rslt' => []);
    }
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";

    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML', // Дозволяє використовувати <b>, <i>, <a>
        'disable_web_page_preview' => true // щоб не захаращувати чат прев'юшками
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // якщо на сервері проблеми з сертифікатами
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Додайте таймаут, щоб скрипт не "висів"

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);

    if ($error) {
        return ['status' => 1, 'str' => 'cURL Error', 'note' => $error, 'rslt' => []];
    }

    $result = json_decode($response, true);

    // Перевіряємо, чи сам Telegram прийняв повідомлення (поле 'ok')
    if ($httpCode !== 200 || !($result['ok'] ?? false)) {
        return [
            'status' => 1, 
            'str' => 'Telegram API Error', 
            'note' => $response, 
            'rslt' => []
        ];
    }
    return array('status'=>0, 'str' => 'ok', 'note'=>$response, 'rslt'=>[]);
    // return json_decode($response, true);
}

// Функція для парсингу User-Agent у короткий зрозумілий формат
function getShortUserAgent() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN_BROWSER';
    
    // Визначаємо операційну систему
    $os = "Unknown OS";
    if (preg_match('/iphone|ipad|ipod/i', $ua)) $os = "iOS";
    elseif (preg_match('/android/i', $ua)) $os = "Android";
    elseif (preg_match('/windows|win32/i', $ua)) $os = "Windows";
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = "MacOS";
    elseif (preg_match('/linux/i', $ua)) $os = "Linux";

    // Визначаємо браузер
    $browser = "Unknown Browser";
    if (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opios|edge/i', $ua)) $browser = "Chrome";
    elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios|opr|opios/i', $ua)) $browser = "Safari";
    elseif (preg_match('/firefox|fxios/i', $ua)) $browser = "Firefox";
    elseif (preg_match('/edge/i', $ua)) $browser = "Edge";
    elseif (preg_match('/opr|opera/i', $ua)) $browser = "Opera";

    return "$os / $browser";
}

function rotateLogs($logDir, $monthsToKeep = 6) {
    if (!is_dir($logDir)) return;

    // Рахуємо граничну дату: 6 місяців тому від сьогодні
    // Наприклад, якщо зараз 2026-05, то ліміт буде 2025-11
    $limitDate = date('Y-m', strtotime("-$monthsToKeep months"));

    // Скануємо папку з логами
    $files = scandir($logDir);
    
    foreach ($files as $file) {
        // Шукаємо файли, які відповідають масці YYYY-MM-auth.log
        if (preg_match('/^(\d{4}-\d{2})-auth\.log$/', $file, $matches)) {
            $fileDate = $matches[1]; // отримуємо "YYYY-MM" з назви файлу

            // Якщо дата файлу менша за ліміт — видаляємо його
            if ($fileDate < $limitDate) {
                @unlink($logDir . '/' . $file);
            }
        }
    }
}
// Функція логування у файл
function writeAuthLog($user, $status, $message) {
    // $logDir = __DIR__ . '/../logs'; 
    $dbg = "Starting logAuth for user=$user status=$status message=$message\n";
    $logDir = LOGS_PATH; // використання константи з lib.php
    if (!file_exists($logDir)) {
        // $dbg .= "Creating log directory: $logDir\n";
        mkdir($logDir, 0755, true);
        // $dbg .= "Creating log directory OK: $logDir\n";
    }
    
    $logFile = $logDir . '/' . date('Y-m') . '-auth.log';
    $dbg .= "Log file path: $logFile\n";
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $date = date('Y-m-d H:i:s');
    
    // Отримуємо пристрій та браузер користувача
    $device = getShortUserAgent();
    
    // Форматуємо рядок логу
    $logMessage = sprintf(
        "[%s] [IP: %s] [Device: %s] [User: %s/%s] [%s] — %s\n", 
        $date, 
        $ip, 
        $device, 
        $user, 
        API_VERSION,
        $status, 
        $message
    );
    // $dbg .= "Formatted log message: $logMessage\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    // ОПТИМІЗАЦІЯ: Запускаємо ротацію тільки при успішних входах, 
    // щоб не навантажувати процесор дисковими операціями при брутфорсі (підборі паролів)
    if ($status === 'SUCCESS') {
        rotateLogs($logDir, 6); // видалити все, що старше 6 місяців
    }
    return $dbg;
}

// Функція для безпечного кодування JWT (Base64URL)
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($b64);
}

function getBearerToken() {
    $headers = null;
    
    // 1. Намагаємось отримати через стандартну функцію (Apache)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $headers = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) { // деякі сервери лоуеркейсять
            $headers = $headers['authorization'];
        } else {
            $headers = null;
        }
    } 
    
    // 2. Альтернатива через $_SERVER (Nginx або якщо Apache не передав заголовки)
    if ($headers === null) {
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { // Для Nginx/FastCGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        }
    }

    // 3. Витягуємо саму строку токена з "Bearer {token}"
    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            return $matches[1];
        }
    }
    return "";
   
    // return null;
}

function validateAndGetPayload() {
    // 1. Отримуємо заголовок Authorization
    // $headers = getallheaders();
    // $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    // if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    //     header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
    //     echo json_encode(['rslt' => null, 'errstr' => 'Token missing']);
    //     exit();
    // }

    // $token = $matches[1];
    
    $token = getBearerToken();
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        echo json_encode(['rslt' => null, 'errstr' => 'Invalid token structure']);
        exit();
    }

    list($header, $payload, $signature) = $parts;

    // 2. ВАЖЛИВО: Перевіряємо підпис за допомогою безпечного декодування (параметр true для бінарного HMAC)
    $key = $_ENV["SHA256_KEY"] ?? getenv('SHA256_KEY') ?? 'default_secret_key';
    $validSignature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $key, true)), '+/', '-_'), '=');

    if ($signature !== $validSignature) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        // return array('status'=>2, 'str' => '401 Unauthorized', 'note'=>json_decode($payload), 'rslt'=>[]);
        echo json_encode(['rslt' => null, 'errstr' => 'Signature verification failed']);
        exit();
    }

    return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>[]);
    // 3. Повертаємо розпарсений об'єкт Payload для використання в SQL (права, термінал)
    return json_decode(base64url_decode($payload));


    // if ($ok) {
    //     return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>$rslt);
    // } else {
    //     header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
    //     return array('status'=>2, 'str' => '401 Unauthorized', 'note'=>json_decode($payload), 'rslt'=>$rslt);
    // }

}
?>