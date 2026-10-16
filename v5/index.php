<?php
// 6. Налаштування типу контенту лише для реальних відповідей (GET, POST тощо)
header("Content-Type: application/json; charset=UTF-8");

require_once "./endpoints.php";

// Перехоплюємо сирі JSON дані з тіла запиту (для Axios / Fetch)
// $rawInput = file_get_contents('php://input');
// $jsonData = json_decode($rawInput, true) ?? [];

$BASE_URI = "/api/v5/";
$requestData = array();
$token = "";

// Складання параметрів запиту
switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        // Поєднуємо дані зі звичайного POST (якщо є) та сирого JSON тіла
        // $requestData = array_merge($_POST, $jsonData);
        $requestData =  $_POST;
        break;
    case 'GET':
        $requestData = $_GET;
        break;
    default:
        break;
}

// Пошук токена API
if (isset($_GET["api_token"])) {
    $token = $_GET["api_token"];
} else if (isset($_SERVER["HTTP_X_API_KEY"])) {
    $token = $_SERVER["HTTP_X_API_KEY"];
} else {
    // Безпечний виклик, якщо функція існує
    $token = function_exists('getBearerToken') ? getBearerToken() : "";
}

$parsedURI = parse_url($_SERVER["REQUEST_URI"]);
$endpointName = str_replace($BASE_URI, "", $parsedURI["path"]);

if (empty($endpointName)) {
    $endpointName = "/";
}

// Обробка POST запитів
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($endpoints_post[$endpointName])) {
        if ($endpointName != "auth") {
            $payload = validateAndGetPayload();
            //  echo "here 222 $endpointName";
           
            // Безпечно дістаємо поле 'data', підтримуючи і стрінг, і готовий масив
            $rawPayloadData = $requestData['data'] ?? '';
            $rq = is_string($rawPayloadData) ? json_decode($rawPayloadData) : (object)$rawPayloadData;
            
            if (!$rq) {
                $rq = new stdClass();
            }
            
            $rq->payload = $payload;
            $endpoints_post[$endpointName]($rq);
        } else {
            $rawAuthData = $requestData['data'] ?? '';
            $authObj = json_decode(base64_decode($rawAuthData));
            $endpoints_post[$endpointName]($authObj);
        }
    } else {
        $endpoints_post["404"](array("endpointName" => $endpointName));
    }    
} 
// Обробка GET запитів
else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($endpoints_get[$endpointName])) {
        $endpoints_get[$endpointName]($requestData);
    } else {
        $endpoints_get["404"](array("endpointName" => $endpointName));
    }    
}

?>
