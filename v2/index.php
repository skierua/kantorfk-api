<?php
require_once "./endpoints.php";

$BASE_URI = "/api/v2/";
$requestData = array();
$token = "";

//collect incoming parameters
switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        // $requestData =  json_decode($_POST['data']);
        $requestData =  $_POST;
        break;
    case 'GET':
        $requestData = $_GET;
        break;
    // case 'DELETE':
    //     $requestData = $_DELETE;
    //     break;
    // case 'PUT':
    // case 'PATCH':
    //     parse_str(file_get_contents('php://input'), $requestData);

    //     //if the information received cannot be interpreted as an arrangement it is ignored.
    //     if (!is_array($requestData)) {
    //         $requestData = array();
    //     }

    //     break;
    default:
        //TODO: implement here any other type of request method that may arise.
        break;
}

//If the token is sent in a header X-API-KEY
if (isset($_GET["api_token"])){
    $token = $_GET["api_token"];
} else if (isset($_SERVER["HTTP_X_API_KEY"])) {
    $token = $_SERVER["HTTP_X_API_KEY"];
}

$parsedURI = parse_url($_SERVER["REQUEST_URI"]);
$endpointName = str_replace($BASE_URI, "", $parsedURI["path"]);

if (empty($endpointName)) {
    $endpointName = "/";
}
// echo "#1ur requestData= $token\n";
// print_r($requestData);



//we define the response encoding, by default we will use json
header("Content-Type: application/json; charset=UTF-8");
if ($_SERVER['REQUEST_METHOD'] == 'POST'){
    // echo "here 111";
    if (isset($endpoints_post[$endpointName])) {
        if ($endpointName != "auth") {
            $resp = parseToken($token);
            if ($resp['status']) {
                // echo "here 222";
                echo json_encode( $resp);
                exit;
            } else {
                // echo "here 333 =".$requestData['data'];
                $endpoints_post[$endpointName](json_decode($requestData['data']));
            }
        } else {
            $endpoints_post[$endpointName](json_decode(base64_decode($requestData['data'])));
        }
    } else {
        $endpoints_post["404"](array("endpointName" => $endpointName));
    }    





} else if ($_SERVER['REQUEST_METHOD'] == 'GET'){
    if (isset($endpoints_get[$endpointName])) {
        $endpoints_get[$endpointName]($requestData);
    } else {
        $endpoints_get["404"](array("endpointName" => $endpointName));
    }    
} else {
    // TODO
}

?>