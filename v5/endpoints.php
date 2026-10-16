<?php
// header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
// header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
require_once "./lib.php";
// $endpoints = array();
$endpoints_get = array();
$endpoints_post = array();

const QUERY_BALANCE_SSE =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
."where (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and (shop='CITY' or shop='FEYA' or shop='EKSV' or shop='BULK');";

const QUERY_BALANCE_ALL =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
."where (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10));";

const QUERY_OFFERS_SEL = "SELECT id, shop, atclcode AS curid, bidask, amnt, price, tel, note, tm FROM vk_offer_crnc;";
const QUERY_OFFERS_SSE = "SELECT vk_offer_crnc.id as oid,  vk_currency.id as curid, chid, name, coalesce(vk_currency.qty,'1') qty, shop, bidask, amnt, price, tel, vk_offer_crnc.note as onote, tm, sortorder FROM vk_offer_crnc join vk_currency on(atclcode=chid) order by tm desc;";

const QUERY_RATES_SEL = "SELECT atclcode, qty, bid, ask, bidtm, asktm, shop, pricecode, scode FROM vk_rate ";
const QUERY_RATES_SSE_OLD = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, "
."sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' order by sortorder;";
// SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' order by sortorder;

const QUERY_RATES_SSE = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, scode, cur.sname, case cur.qty when '' then 1 else cur.qty end as cqty, "
."sortorder, pricecode as prc, cur.domestic FROM vk_rate join (SELECT id as atclcode, chid, name, symb, qty, sortorder, domestic, coalesce(scode,'') scode, coalesce(sname,'') sname "
."FROM `vk_currency` left join vk_cur_sub on id=atclcode) as cur USING(atclcode, scode) where sortorder!='' order by sortorder, scode;";

// Помічник для екранування стрінгів (захист від SQL ін'єкцій)
function sanitize($data) {
    global $global_db_connection;

    // $db = dbconnect();
    return $global_db_connection ? $global_db_connection->real_escape_string(trim($data)) : trim($data);
}

// Помічник для стандартизації успішної відповіді під фронтенд
function sendJsonResponse($data) {
    echo json_encode(array('status' => 0, 'str' => 'ok', 'note' => '', 'rslt' => $data));
}

// --- БАЗОВІ ЕНДПОІНТИ ---
$endpoints_get["/"] = function (array $requestData): void {
    sendJsonResponse("Welcome to my GET API!");
};
$endpoints_post["/"] = function (array $requestData): void {
    sendJsonResponse("Welcome to my POST API!");
};

function badRequestId(string $reqid, string $endpoint): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
    echo json_encode(array(
        'status' => 400, 
        'str' => 'Bad Request', 
        'note' => "Request '" . htmlspecialchars($reqid, ENT_QUOTES, 'UTF-8') . "/". $endpoint. "' is not supported.",
        'rslt' => []
    ));
    exit();
}
/**
 * prints a default message if the endpoint path does not exist.
 * @param array $requestData contains the parameters sent in the request, 
 *                           for this endpoint they are ignored.
 * @return void
 */
// --- СТАНДАРТНІ ПОМИЛКИ 404 ---
$endpoints_get["404"] = function ($requestData): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    $endpoint = $requestData["endpointName"] ?? 'unknown';
    echo json_encode(array(
        'status' => 404, 
        'str' => '404 not found', 
        'note' => "Endpoint GET /$endpoint not found.", 
        'rslt' => [] // Виправлено помилку з $resp
    ));
};

$endpoints_post["404"] = function ($requestData): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    $endpoint = $requestData["endpointName"] ?? 'unknown';
    echo json_encode(array(
        'status' => 404, 
        'str' => '404 not found', 
        'note' => "Endpoint POST /$endpoint not found.", 
        'rslt' => []
    ));
};

// --- ЕНДПОІНТ: OFFERS ---
$endpoints_get["offers"] = function ($requestData): void { 
    $reqid = sanitize($requestData["reqid"] ?? "EMPTY");
    // $shop = sanitize($requestData["shop"] ?? "");
    
    // $flt = ($shop === "" ? "" : " WHERE shop='$shop'");
    
    // Запис логу відладки через безпечні параметри
    // sqlReq("REPLACE INTO vk_dbg_func (id, tm, note) VALUES ('" . API_VERSION . "/offers/$reqid', '" . date("Y-m-d H:i:s") . "', '') ;");
    if ($reqid === "sel"){
        echo json_encode(sqlExec(QUERY_OFFERS_SEL));
        return;
    } else if ($reqid === "sse") {
        echo json_encode(sqlReq_cache(QUERY_OFFERS_SSE));
        return;
    } else {
        badRequestId($reqid, "offers");
    }
};

// --- ЕНДПОІНТ: ARCHIVE ---
$endpoints_get["archive"] = function ($requestData) {   // DEPRECATED: use reports instead
    $reqid = sanitize($requestData["reqid"] ?? "EMPTY");
    $cur = sanitize($requestData["cur"] ?? "840");
    $period = sanitize($requestData["period"] ?? date("Y-m-d"));
    $rateCode = sanitize($requestData["code"] ?? DFLT_TRADE_ACNT ?? "3500");
    
    if ($reqid === "ratesAvrg") {
        $start = date("Y-m-d", strtotime("-1 month 1 day", strtotime($period)));
        $finish = date("Y-m-d", strtotime("+1 day", strtotime($period)));
        
        // Fix: Removed the extra single quote after $cur
        $vquery = "SELECT period, atclcode, ba, qty, amnt, eq, shop, acnt, cashier, minrate, maxrate FROM vk_daycurslt "
        . "WHERE (shop='CITY' OR shop='FEYA') AND atclcode='$cur' AND acnt = '$rateCode' AND period > '$start' AND period < '$finish';";
        
        echo json_encode(sqlReq_cache($vquery, 3600));
        return;
   } else {
        badRequestId($reqid, "archive");
        return;
    }
};

$endpoints_get["reports"] = function ($requestData) { 
    $reqid = sanitize($requestData["reqid"] ?? "EMPTY");
    // $cur = sanitize($requestData["cur"] ?? "840");
    $period = sanitize($requestData["period"] ?? date("Y-m-d"));
    $rateCode = sanitize($requestData["code"] ?? DFLT_TRADE_ACNT ?? "3500");
    if ($reqid === "dayCurRslt") {
        $start = date("Y-m-d", strtotime("-1 month 1 day", strtotime($period)));
        $finish = date("Y-m-d", strtotime("+1 day", strtotime($period)));
        $param = [ $rateCode, $start, $finish];
        // $param = [$cur, $rateCode, $start, $finish];
        
        // Fix: Removed the extra single quote after $cur
        $vquery = "SELECT period, atclcode, ba, qty, amnt, eq, shop, acnt, cashier, minrate, maxrate FROM vk_daycurslt "
        . "WHERE (shop='CITY' OR shop='FEYA') AND acnt = ? AND period > ? AND period < ?;";
        // . "WHERE (shop='CITY' OR shop='FEYA') AND atclcode= ? AND acnt = ? AND period > ? AND period < ?;";
        
        echo json_encode(sqlExec($vquery, 3600, $param));
        return;
   } else {
        badRequestId($reqid, "GET reports");
        return;
    }
};

// --- ЕНДПОІНТ: CURRENCIES ---
$endpoints_get["currencies"] = function ($requestData): void { 
    $reqid = sanitize($requestData["reqid"] ?? "");
    $vquery = "";
    
    if ($reqid === "sel") { 
        $vquery = "SELECT id, chid, name, symb, flag, qty, sortorder so, domestic dmst FROM vk_currency ;";  
        // $vquery = "SELECT id, chid, name, symb, flag, case qty when '' then '1' else qty end qty, sortorder so, domestic dmst FROM vk_currency where sortorder != '' order by sortorder;";  
    } else if ($reqid === "selsub") {
        $vquery = "SELECT atclcode, scode as id, sname FROM vk_cur_sub ORDER BY scode;";
    } else {
        badRequestId($reqid, "currencies");
    }
    
    echo json_encode(sqlExec($vquery, 3600));
}; 

$endpoints_get["rates"] = function ($requestData): void {
    $vquery = "";
    $reqid = sanitize($requestData['reqid'] ?? 'EMPTY');
    $shop = sanitize($requestData["shop"] ?? "");
    $pricecode = sanitize($requestData["pricecode"] ?? "");

    // Логування виклику
    // sqlReq("REPLACE INTO vk_dbg_func (id, tm, note) VALUES ('" . API_VERSION . "/rates/$reqid', '" . date("Y-m-d H:i:s") . "', '') ;");

    if ($reqid === "sel") { 
         // ЗАХИСТ: Екрануємо параметри, щоб уникнути хакерських ін'єкцій через POST
        $param = [];
        $where_clauses = [];
        
        if (!empty($shop)) {
            $where_clauses[] = "shop = ?";
            $param[] = $shop;
        }
        if (!empty($pricecode)) { 
            $where_clauses[] = "pricecode = ?";
            $param[] = $pricecode;
        } else {
            $where_clauses[] = "(pricecode IS NULL OR pricecode = '')"; // Використовуємо дефолтний pricecode, якщо не вказано
        }
        $where_str = implode(" AND ", $where_clauses);

        
        $vquery = QUERY_RATES_SEL." WHERE $where_str ;";
        
        echo json_encode(sqlExec($vquery, 0, $param));
    //    echo json_encode(sqlReq($vquery));
        return;
    } elseif ($reqid === "sse") { 
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE));
    } else {
        badRequestId($reqid, "rates");
    }
};

$endpoints_post["app_api"] = function ($post): void { 
    
    $reqid = sanitize($post->reqid ?? "");

    if ($reqid === "updacnt") {      
        if (!isset($post->data) || !is_array($post->data)) {
            echo json_encode(['status' => 1, 'str' => 'No data provided', 'rslt' => []]);
            return;
        }
        $post_shop = sanitize($post->shop ?? "");
        $delPrevious = sanitize($post->del ?? 0);
        if ($delPrevious) {
            $vquery = "delete from vk_acntst where shop='$post_shop'";
            $res = sqlReq($vquery);
            if ($res['status'] === 0) {
                sqlCache_clear(QUERY_BALANCE_SSE);
                sqlCache_clear(QUERY_BALANCE_ALL);
            }
        }

        $val = "";
        $vquery = "REPLACE INTO vk_acntst (acntno, atclcode, amnt, turndbt, turncdt, shop, tm) VALUES ";
        
        foreach ($post->data as $row) {
            $s_acntno = sanitize($row->acntno ?? "");
            $s_artid  = sanitize($row->articleid ?? "");
            $s_amnt   = sanitize($row->amnt ?? "0");
            $s_tdbt   = sanitize($row->turndbt ?? "0");
            $s_tcdt   = sanitize($row->turncdt ?? "0");
            $s_tm     = sanitize($row->tm ?? gmdate("Y-m-d\TH:i:s\Z"));

            $val .= ($val !== "" ? "," : "") . "('$s_acntno', '$s_artid', '$s_amnt', '$s_tdbt', '$s_tcdt', '$post_shop', '$s_tm')";
        }
        
        $vquery .= $val . ";";
        $res = sqlReq($vquery);

        if ($res['status'] === 0) {
            sqlCache_clear(QUERY_BALANCE_SSE);
            sqlCache_clear(QUERY_BALANCE_ALL);
        }

        echo json_encode($res);

    } else if ($reqid === "upddcm") {   
        if (function_exists('sqlAddBind')) {
            echo json_encode(sqlAddBind($post->data, $post->term));
        } else {
            echo json_encode(['status' => 1, 'str' => 'sqlAddBind function missing', 'rslt' => []]);
        }
    } else if ($reqid === "updprofit") { 

        $res = sqlUpdMonRepo($post, "profit");

        echo json_encode($res);
        return;
    } else if ($reqid === "selrate") {
        // ЗАХИСТ: Екрануємо параметри, щоб уникнути хакерських ін'єкцій через POST
        $post_shop = sanitize($post->shop ?? "");
        $post_pricecode = sanitize($post->pricecode ?? "");
        $param = [];
        $where_clauses = [];
        
        if (!empty($post_shop)) {
            $where_clauses[] = "shop = ?";
            $param[] = $post_shop;
        }
        if (!empty($post_pricecode)) { 
            $where_clauses[] = "pricecode = ?";
            $param[] = $post_pricecode;
        }
        $where_str = implode(" AND ", $where_clauses);

        
        $vquery = QUERY_RATES_SEL." WHERE $where_str ;";
        
        echo json_encode(sqlExec($vquery, 0, $param));
    //    echo json_encode(sqlReq($vquery));
        return;
    } else {
        badRequestId($reqid, "app_api");
    }
};

$endpoints_post["accounts"] = function ($post): void { 
    global $global_payload; // ОТРИМУЄМО ПАЙЛОАД JWT ТОКЕНА З РОУТЕРА
    $vquery = "";
    
    // Безпечне читання та очищення базових параметрів
    $reqid = sanitize($post->reqid ?? "");
    $shop_post = sanitize($post->shop ?? "");

    if ($reqid === "sse") {      
        echo json_encode(sqlReq_cache(QUERY_BALANCE_SSE));
    } else {
        badRequestId($reqid, "accounts");
    }
};

$endpoints_post["auth"] = function ($post): void { 
    global $global_db_connection;
    global $global_payload;

    if ($global_db_connection === null) {
        $global_db_connection = dbconnect();

        // $global_db_connection = dbconnect();
        if ($global_db_connection->connect_error) {
            writeAuthLog($post->usr ?? 'UNKNOWN', 'ERROR', 'Database connection failed: ' . $global_db_connection->connect_error);
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            echo json_encode(array('token' => '', 'errstr' => 'Server database error.'));
            exit();
        }
    }
    $db = $global_db_connection;

    // 1. ЗАХИСТ: Використовуємо Prepared Statement проти SQL-ін'єкцій
    $stmt = $db->prepare("SELECT user_pwd, user_role, salt FROM vk_users WHERE user_role != '' AND user_login = ?");
    $stmt->bind_param("s", $post->usr);
    $stmt->execute();
    $rslt = $stmt->get_result();
    $crnt_user = $rslt->fetch_row();
    $stmt->close();

    if (!$crnt_user) {
        writeAuthLog($post->usr, 'FAILED', 'User not found or role is empty');
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo json_encode(array('token' => '', 'errstr' => 'Authorization error.'));
        exit();
    }
    
    // 2. ЗАХИСТ: Перевірка пароля (залишив вашу логіку солі, якщо вона вже так зашита в БД)
    $password_to_check = $post->psw . $crnt_user[2]; 
    if (!password_verify($password_to_check, $crnt_user[0])) { 
        // Оновлюємо статус у БД
        $errnote = 'Failed login attempt';
        $stmt_upd = $db->prepare("UPDATE vk_users SET lastrequest = CURRENT_TIMESTAMP, note = ? WHERE user_login = ?");
        $stmt_upd->bind_param("ss", $errnote, $post->usr);
        $stmt_upd->execute();
        $stmt_upd->close();

        // Логуємо неуспішний вхід
        writeAuthLog($post->usr, 'UNAUTHORIZED', 'Wrong password entered');

        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        echo json_encode(array('token' => '', 'errstr' => 'Authorization error.'));
        exit();
    }

    // Визначення терміналу
    $cus = "";
    if ($crnt_user[1] == "owner") {
        $cus = "BULK";
    } else {
        $cus = ($post->usr == "ownerwife") ? "FEYA" : $post->usr;
    }

    // 3. ВИПРАВЛЕННЯ JWT: Кодуємо через правильний base64url
    $vheader = json_encode(array('alg' => 'HS256', 'typ' => 'JWT'));
    $global_payload = json_encode(array('crntuser' => $cus, 'term' => $cus, 'role' => $crnt_user[1], 'user' => $post->usr));
    
    $unsignedToken = base64url_encode($vheader) . '.' . base64url_encode($global_payload);
    
    // Захист від відсутності ключа в .env
    $key = $_ENV["SHA256_KEY"] ?? getenv('SHA256_KEY') ?? 'default_secret_key';
    $signature = base64url_encode(hash_hmac('sha256', $unsignedToken, $key, true)); // true повертає бінарні дані для HMAC

    // Логуємо успішний вхід
    $dbg = writeAuthLog($post->usr, 'SUCCESS', 'Successfully logged in. Terminal: ' . $cus);

    echo json_encode(array('token' => $unsignedToken . '.' . $signature, 'log' => 'User ' . $post->usr . ' authenticated successfully with role ' . $crnt_user[1], 'dbg' => $dbg)); 
};

$endpoints_post["balance"] = function ($post): void { 
    global $global_payload; 

    $reqid = sanitize($post->reqid ?? "");
    
    $shops = (isset($post->shop) && is_array($post->shop)) ? $post->shop : [];

    if ($reqid === "sel") {
        
        // Якщо масив магазинів порожній, повертаємо порожній результат або помилку
        // if (empty($shops)) {
        //     echo json_encode(['status' => 0, 'str' => 'Blank result', 'note' => 'No shops provided', 'rslt' => []]);
        //     return;
        // }

        // Очищуємо кожен елемент масиву (санітизація)
        $cleanShops = array_map('sanitize', $shops);

        $whereClause = "";
        if (count($cleanShops) != 0 ){
            // Динамічно створюємо рядок із знаків питання: наприклад, "?, ?, ?"
            $placeholders = implode(', ', array_fill(0, count($cleanShops), '?'));
            $whereClause = "WHERE shop IN ($placeholders)";
        }



        $vquery = "SELECT acntno, atclcode, amnt, turndbt, turncdt, shop, tm "
                . "FROM vk_acntst $whereClause ;";

        echo json_encode(sqlExec($vquery, -1, $cleanShops));
        return;

    } else {
        badRequestId($reqid, "balance");
        return;
    }
};

$endpoints_post["dcms"] = function ($post): void { 
    $vquery = "";
    
    // Безпечне очищення базових параметрів
    $reqid = sanitize($post->reqid ?? "");

    if ($reqid === "sel") {
        $flt = "";
        
        if (isset($post->acnt)) { 
            $acnt = sanitize($post->acnt);
            $flt .= ($flt === "") ? " (cdt = '$acnt' or vk_dcm.dbt = '$acnt')" : " and (cdt = '$acnt' or vk_dcm.dbt = '$acnt')"; 
        }
        if (isset($post->cur)) { 
            $cur = sanitize($post->cur);
            $flt .= ($flt === "") ? " atclcode = '$cur'" : " and atclcode = '$cur'"; 
        }
        if (isset($post->shop)) { 
            $shop = sanitize($post->shop);
            $flt .= ($flt === "") ? " shop = '$shop'" : " and shop = '$shop'"; 
        }
        if (isset($post->from)) { 
            $from = sanitize($post->from);
            $flt .= ($flt === "") ? " tm >= '$from'" : " and tm >= '$from'"; 
        }
        if (isset($post->to)) { 
            $to = sanitize($post->to);
            $flt .= ($flt === "") ? " DATE(tm) <= '$to'" : " and DATE(tm) <= '$to'"; 
        }
        
        if ($flt !== "") { 
            $flt = " where $flt"; 
        } 

        $pagination = "";
        if (isset($post->offset) || isset($post->limit)) { 
            $limit_val = isset($post->limit) ? (int)$post->limit : 50; // дефолтний ліміт 50 записів
            $offset_val = isset($post->offset) ? (int)$post->offset : 0;
            
            $pagination = " LIMIT $limit_val OFFSET $offset_val";
        }

        $vquery = "SELECT vk_dcmbind.dcmcode as bcode, vk_dcmbind.dbt as bacnt, vk_dcmbind.amnt as bamnt, vk_dcmbind.eq as beq, vk_dcmbind.dsc as bdsc, vk_dcmbind.bns as bbns,"
        ." clientid, tm, shop, cshr, vk_dcm.id, pid, vk_dcm.dcmcode as dcmcode, vk_dcm.amnt as amnt, vk_dcm.eq as eq, vk_dcm.dsc as dsc, vk_dcm.bns as bns, vk_dcm.note as note,"
        ." vk_dcm.atclcode as atclcode, vk_dcm.dbt, cdt from vk_dcmbind join vk_dcm on (vk_dcmbind.id=pid) $flt order by tm desc, pid desc $pagination;";
        
        echo json_encode(sqlReq($vquery));

    } else {
        badRequestId($reqid, "dcms");
    }
};

$endpoints_post["offers"] = function ($post): void { 
    $vquery = "";
    
    $reqid = sanitize($post->reqid ?? "");

    if ($reqid === "upd") {   
        $ofid  = sanitize($post->ofid ?? "");
        $shop  = sanitize($post->shop ?? "CITY");
        $cur   = sanitize($post->cur ?? "USD");
        $ba    = sanitize($post->ba ?? "bid");
        $amnt  = sanitize($post->amnt ?? "");
        $price = sanitize($post->price ?? "");
        $tel   = sanitize($post->tel ?? "");
        $note  = sanitize($post->note ?? "");
        
        // Для часу використовуємо поточний ISO рядок, якщо з фронтенду прийшло порожнє поле
        $tm    = sanitize(!empty($post->tm) ? $post->tm : date('Y-m-d H:i:s'));

        if ($ofid !== "" && ($amnt === "" || $price === "")) {   
            // Видалення (якщо прислали ID, але сума або курс порожні)
            $vquery = "DELETE FROM vk_offer_crnc WHERE id = '$ofid' ";
            
        } else if ($ofid !== "") {        
            // Оновлення існуючої пропозиції
            $vquery = "UPDATE vk_offer_crnc SET shop='$shop', atclcode='$cur', bidask='$ba', amnt='$amnt', price='$price', tel='$tel', note='$note', tm='$tm' WHERE id = '$ofid' ";
            
        } else {        
            // Створення нової пропозиції (якщо ofid порожній)
            $vquery = "INSERT INTO vk_offer_crnc (shop, atclcode, bidask, amnt, price, tel, note, tm) VALUES ('$shop', '$cur', '$ba', '$amnt', '$price', '$tel', '$note', '$tm')";
        }

        // Виконуємо безпечний SQL-запит
        $res = sqlReq($vquery);

        if ($res['status'] === 0 && function_exists('sqlCache_clear')) {
            sqlCache_clear(QUERY_OFFERS_SEL);
            sqlCache_clear(QUERY_OFFERS_SSE);
        }

        echo json_encode($res);
        exit();

    } else {
        if (function_exists('badRequestId')) {
            badRequestId($reqid, "offers");
        } 
        exit();
    }
}; 

$endpoints_post["rates"] = function ($post): void {
    $vquery = "";
    
    // Безпечне читання та очищення базових параметрів запиту
    $reqid = sanitize($post->reqid ?? "");

    // if ($reqid === "sel") {
    //     // ЗАХИСТ: Екрануємо параметри, щоб уникнути хакерських ін'єкцій через POST
    //     $shop = sanitize($post->shop ?? "");
    //     $pricecode = sanitize($post->pricecode ?? "");
        
    //     $flt = "";
    //     if ($shop !== "") { 
    //         $flt .= ($flt === "") ? " shop = '$shop'" : " and shop = '$shop'"; 
    //     }
    //     if ($pricecode !== "") { 
    //         $flt .= ($flt === "") ? " pricecode = '$pricecode'" : " and pricecode = '$pricecode'"; 
    //     }
        
    //     if ($flt !== "") { 
    //         $flt = " and $flt"; 
    //     } 
        
    //     $vquery = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, "
    //     ."sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' $flt order by sortorder;";
        
    //     echo json_encode(sqlReq($vquery));

    // } else if ($reqid === "ssedb") {      
    //     echo json_encode(sqlReq_cache(QUERY_RATES_SSE, 0));
    // } else if ($reqid === "sse_old") {      
    //     echo json_encode(sqlReq_cache(QUERY_RATES_SSE_OLD));
    // } else if ($reqid === "sse") { 
    //     echo json_encode(sqlReq_cache(QUERY_RATES_SSE));
    // } else 
    if ($reqid === "upd") {      
        if (!isset($post->rates) || !is_array($post->rates)) {
            echo json_encode(['status' => 1, 'str' => 'Rates data is missing', 'rslt' => []]);
            return;
        }

        // Виконуємо функцію оновлення курсів у базі даних
        $res = sqlUpdRate($post->rates);

        if ($res['status'] === 0 && function_exists('sqlCache_clear')) {
            sqlCache_clear(QUERY_RATES_SSE);
            // sqlCache_clear(QUERY_RATES_SSE_OLD);
        }

        echo json_encode($res);
    } else {
        badRequestId($reqid, "rates");
    }
}; 

$endpoints_post["reports"] = function ($post): void {
    global $global_payload;
    
    $vquery = "";
    $vfilter = "1";
    
    // Чисті та безпечні параметри з токена
    $kant = sanitize($global_payload->term ?? "");
    $userRole = sanitize($global_payload->role ?? "kant");

    // Безпечне чищення вхідних POST параметрів
    $reqid = sanitize($post->reqid ?? "");
    $post_code = sanitize($post->code ?? "");
    $post_shop = sanitize($post->shop ?? "");
    $post_from = sanitize($post->from ?? "");
    $post_to   = sanitize($post->to ?? "");

    if ($reqid === "updprofit") { // DEPRECATED: use app_api endpoint instead
        $res = sqlUpdMonRepo($post, "profit");

        echo json_encode($res);
        return;
    } else if ($reqid === "acnt") { // Звіт по залишках рахунків DEPRECATED: use accounts endpoint instead
        $vacnt = ($post_code !== "") ? $post_code : "3000";
        
        if ($vacnt !== "3003" && (substr($vacnt, 0, 2) !== "35")) {
            if ($userRole !== "owner") { 
                $vfilter = "code='$kant'"; 
            }
        }
        
        $vacntLen = strlen($vacnt);
        if (substr($vacnt, 0, 2) === "30") {
            $vquery = "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt as total, turndbt as income, turncdt as outcome, tm, acntno "
            ."from vk_acntst left join vk_currency on(id=atclcode) join vk_shop on(vk_acntst.shop=vk_shop.code) "
            ."where substr(acntno,1,$vacntLen)='$vacnt' and permit and (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and $vfilter order by coalesce(vk_currency.sortorder,0),vk_shop.sortorder,substr(acntno,1,4),tm desc;";
        } else {
            $vquery = "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, 0-amnt as total, turndbt as outcome, turncdt as income, tm, acntno "
            ."from vk_acntst left join vk_currency on(id=atclcode) join vk_shop on(vk_acntst.shop=vk_shop.code) "
            ."where substr(acntno,1,$vacntLen)='$vacnt' and permit and (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and $vfilter order by coalesce(vk_currency.sortorder,0),vk_shop.sortorder,substr(acntno,1,4),tm desc;";
        }
     } else if ($reqid === "crntTradeOper") { // Обороти по торгівлі (curent trade operations)
        $post_tm = sanitize($post->tm ?? date('Y-m-d'));
        // $tmLen = strlen($post_tm);
        $param = [$post_tm];
        $vquery = "SELECT SUBSTRING(tm, 1,10) AS period, vk_dcm.atclcode, vk_dcm.dcmcode, shop, SUM(vk_dcm.amnt) AS amnt, ROUND(SUM(vk_dcm.eq), 2) AS eq "
        ." FROM vk_dcmbind join vk_dcm on (vk_dcmbind.id=pid) "
        ." WHERE (shop='CITY' OR shop='FEYA') AND LENGTH(vk_dcm.atclcode) = 3 AND (vk_dcm.dcmcode = 'trade:buy' OR vk_dcm.dcmcode = 'trade:sell') AND SUBSTRING(tm, 1,10) = ? "
        ." GROUP BY SUBSTRING(tm, 1,10), vk_dcm.atclcode, vk_dcm.dcmcode, shop ;";
        // echo json_encode(sqlReq_cache($vquery, 900));
        echo json_encode(sqlExec($vquery, 900, $param));
        return;
     } else if ($reqid === "moncurprofit") { 
        $post_shop = sanitize($post->shop ?? "");
        // 1. Формуємо дати (Рекомендується 'Y-m-d', наприклад '2026-09-01')
        // Якщо в базі суворо тип VARCHAR або DATE, краще підігнати під формат бази
        $finish = sanitize($post->to ?? date("Y-m"));
        $start = sanitize($post->from ?? date("Y-m", strtotime("-3 month", strtotime($finish))));

        $param = [];
        $where_clauses = ["vk_mnrslt2.code = 'profit'"];


        // Перші два знаки '?' в SQL будуть для дат
        $where_clauses[] = "period <= ?";
        $param[] = $finish;

        $where_clauses[] = "period >= ?";
        $param[] = $start;

        // 2. Динамічно додаємо магазин у кінець списку умов та параметрів
        if (!empty($post_shop)) {
            $where_clauses[] = "shop = ?";
            $param[] = $post_shop;
        }

        // Зклеюємо всі умови через AND
        $where_str = implode(" AND ", $where_clauses);
        
        $vquery = "SELECT period, acnt, shop, atclcode, amnt, cashier  "
                . "FROM vk_mnrslt2 JOIN vk_currency ON (vk_mnrslt2.atclcode = vk_currency.id) "
                . "WHERE $where_str;";       

        echo json_encode(sqlExec($vquery, 900, $param));
        return;
    // } else if ($reqid === "kntprofit") { // Дохід по касах
    //     $vfilter = ($post_shop !== "") ? "and shop='$post_shop'" : "";
        
    //     $vquery = "SELECT acnt as gr0, shop as gr1, chid as code, period, CONCAT('/',acnt,'/',vk_shop.sortorder,'/',coalesce(vk_currency.sortorder,'99')) as so, amnt "
    //     ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
    //     ."WHERE period >= '$post_from' and period <= '$post_to' and vk_mnrslt2.code = 'profit' $vfilter order by so;";
        
    // } else if ($reqid === "curprofit") { // Дохід по валютах
    //     $vfilter = ($post_shop !== "") ? "and shop='$post_shop'" : "";
        
    //     $vquery = "SELECT acnt as gr0, chid as gr1, shop as code, period, CONCAT('/',acnt,'/',coalesce(vk_currency.sortorder,'99'),'/',vk_shop.sortorder) as so, amnt "
    //     ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
    //     ."WHERE period >= '$post_from' and period <= '$post_to' and vk_mnrslt2.code = 'profit' $vfilter order by so;";
        
    // } else if ($reqid === "cshprofit") { // Дохід по касирах
    //     $vfilter = ($post_shop !== "") ? "and shop='$post_shop'" : "";
        
    //     $vquery = "SELECT cashier as gr0, chid as gr1, shop as code, period, CONCAT('/',cashier,'/',coalesce(vk_currency.sortorder,'99'),'/',vk_shop.sortorder) as so, amnt "
    //     ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
    //     ."WHERE period >= '$post_from' and period <= '$post_to' and vk_mnrslt2.code = 'profit' and acnt='3500' $vfilter order by so;";
        
    // } else if ($reqid === "chartprofit") { // Аналітика та зарплати під графіки
    //     $vquery = "SELECT acnt, shop, cashier, chid, period, vk_mnrslt2.amnt total, vk_wage.amnt wage, vk_currency.sortorder cso, vk_shop.sortorder sso "
    //     ." FROM vk_mnrslt2 left join vk_wage using(period,cashier) join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
    //     ." WHERE period >= '$post_from' and period <= '$post_to' and vk_mnrslt2.code = 'profit' and cashier!=''  and acnt='3500';";
    } else {
        badRequestId($reqid, "reports");
    }
    
    // Напряму повертаємо результат оптимізованої функції sqlReq
    echo json_encode(sqlReq($vquery));
};

$endpoints_post["publish_tg"] = function ($post): void {
    echo json_encode( sendTelegramMessage($post->message));
};

/**
 * checks if the token is valid, and prevents the execution of 
 * the requested endpoint.
 * @param array $requestData contains the parameters sent in the request, 
 *                           for this endpoint is required an item with 
 *                           key "token" that contains the token
 *                           received to authenticate and authorize 
 *                           the request.
 * @return void
 */
$endpoints_post["checktoken"] = function ($requestData): void {

    //you can create secure tokens with this line, but that is a discussion for another post.. 
    //but i am using UUIDv4 instead.
    //$token = str_replace("=", "", base64_encode(random_bytes(160 / 8)));

    //authorized tokens
    // $tokens = array( "fa3b2c9c-a96d-48a8-82ad-0cb775dd3e5d" => "" );

    if (!isset($requestData["api_token"])) {
        echo json_encode("No token was received to authorize the operation. Verify the information sent");

        exit;
    }
    $ok = false;
    $rslt = [];
    $tarr = explode(".", $requestData["api_token"]);

    $payload = base64_decode($tarr[1]);
    $ok =  ($tarr[2] == hash_hmac('sha256',$tarr[0] . "." . $tarr[1], $_ENV["SHA256_KEY"]));
    if ($ok) {
        // return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>$rslt);
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        // return array('status'=>2, 'str' => '401 Unauthorized', 'note'=>json_decode($payload), 'rslt'=>$rslt);
    }
    
    // if (!isset($tokens[$requestData["api_token"]])) {
    //     echo json_encode("The token  " . $requestData["api_token"] . 
    //     " does not exists or is not authorized to perform this operation.");
        
    //     exit;
    // }
};


/**
 * ServerSendEvent
 * 
 */
$endpoints_get["sse"] = function ($requestData, $token): void { 
    // header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    // header("Access-Control-Allow-Origin: http://localhost:3000");
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");

    $counter =0;
    $vquery = "";
    $key = "";
    $ev = "";
    $resp = array();
    $acntSSE = false;
    if (isset($token) && ($token!="")){
        $resp = parseToken($token);
        $acntSSE = !$resp['status'];
    }
    while (true) {
        if ($acntSSE) {
            $vquery =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
            ."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
            ."where (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and (shop='CITY' or shop='FEYA' or shop='EKSV' or shop='BULK');";
            echo "event: account_stream\n";
            echo "data: " . json_encode(sqlReq_cache(QUERY_BALANCE_SSE)) . "\n";
            echo "\n\n";
            if (ob_get_contents()) {
                ob_end_flush();
            }
        }

        $vquery = "SELECT vk_offer_crnc.id as oid,  vk_currency.id as curid, chid, name, coalesce(vk_currency.qty,'1') qty, shop, bidask, amnt, price, tel, vk_offer_crnc.note as onote, tm, sortorder FROM vk_offer_crnc join vk_currency on(atclcode=chid) order by tm desc;";
        echo "event: offer_stream\n";
        echo "data: " . json_encode(sqlReq_cache(QUERY_OFFERS_SSE)) . "\n";
        echo "\n\n";
        if (ob_get_contents()) {
            ob_end_flush();
        }

        $vquery = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, "
        ."sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' order by sortorder;";
        echo "event: rate_stream\n";
        echo "data: " . json_encode(sqlReq_cache(QUERY_RATES_SSE)) . "\n";
        echo "\n\n";
        if (ob_get_contents()) {
            ob_end_flush();
        }
        flush();
        if (connection_aborted()) break;
        sleep(10); 
    }

    
}; 

// $endpoints_post["offers_clear_cache"] = function ($post): void {
//     // 1. Оновлюємо або створюємо оффер в БД
//     $vquery = "UPDATE vk_offer_crnc SET price = '...', amnt = '...' WHERE id = '...'"; // або INSERT
//     $db_result = sqlReq($vquery);
    
//     if ($db_result['status'] === 0) {
//         // ✅ КЕШ ОЧИЩЕНО МИТТЄВО:
//         // Наступний GET запит від будь-якого касира змусить Memcached 
//         // піти в базу даних за свіжими даними, ігноруючи 8-секундну затримку!
//         sqlCache_clear(QUERY_OFFERS_SSE);
        
//         // Логуємо подію (за бажанням)
//         writeAuthLog($post->usr ?? 'SYSTEM', 'POST_OFFER', 'Offer updated, cache cleared');
//     }
    
//     // Повертаємо результат на фронтенд
//     echo json_encode($db_result);
// };

?>
