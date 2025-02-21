<?php
define("PROJECT_ROOT_PATH", __DIR__ . "/");
// echo "lib.php path=".PROJECT_ROOT_PATH."\n";
require_once PROJECT_ROOT_PATH . "DotEnvLoader.php";
// // include main configuration file 
// require_once PROJECT_ROOT_PATH . "/inc/config.php";
// // include the base controller file 
// require_once PROJECT_ROOT_PATH . "/Controller/Api/BaseController.php";
// // include the use model file 
// require_once PROJECT_ROOT_PATH . "/Model/UserModel.php";

$gl_lastQuery = "";
$gl_payload = ""; // string not JSON !!!

function parseToken($token){
    $ok = false;
    $rslt = [];
    $tarr = explode(".", $token);
    // $payload = json_decode(base64_decode($atoken[1]));

    $payload = base64_decode($tarr[1]);
    $ok =  ($tarr[2] == hash_hmac('sha256',$tarr[0] . "." . $tarr[1], $_ENV["SHA256_KEY"]));
    if ($ok) {
        return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>$rslt);
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        return array('status'=>2, 'str' => '401 Unauthorized', 'note'=>json_decode($payload), 'rslt'=>$rslt);
    }
}

function dbconnect(){
    $db1 = new mysqli($_ENV['DB_HOST'].":".$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME']);
    $db1->set_charset("utf8");
    return @$db1;
}

function sqlReq($vquery, $logid="", $lognote="" ){
    $resp = [];
    $db = new mysqli($_ENV['DB_HOST'].":".$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME']);
    $db->set_charset("utf8");
    if ($db->connect_errno) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>$db->connect_errno, 'str' => $db->connect_error, 'note'=>$_ENV['DB_USER']."@".$_ENV['DB_HOST'].":".$_ENV['DB_PORT'], 'rslt'=>$resp);
        // exit();
    }
    $tquery = trim($vquery);    // trimed query
    if ($tquery == ""){
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Page not found');
        return array('status'=>1, 'str' => 'Page not found', 'note'=>$vquery, 'rslt'=>$resp);
    }
    $ok = false;
    $rslt = $db->query($tquery);
    $err = (integer)$db->errno;
    if (!$err){
        $ok = true;
        // echo "/lib.php err=$err ok=$ok qry=$tquery";
        if ( mb_strtolower(substr($tquery,0,3)) == "sel" ) {
            $fname = [];
            // echo "/lib.php err=$err ok=$ok fname=".mysqli_num_fields($rslt);
            for ($i=0; $i < mysqli_num_fields($rslt); ++$i){
                // $finfo = mysqli_fetch_field($rslt);
                $fname[$i] = mysqli_fetch_field($rslt)->name;
            }
            while ($row = $rslt->fetch_row()) {
                $arow = [];
                $rt_data = "";
                for ($i=0; $i < mysqli_num_fields($rslt); ++$i){
                    $arow[$fname[$i]] = $row[$i];
                }
                $resp[] = $arow;
            }
        }
        // api log
        if (isset($logid) && ($logid != "")) {
            $rslt = $db->query("replace into vk_dbg_func (id, tm, note) values ('v1/$logid', '".date("Y:m:d H:i:s")."', '$lognote') ;");
        }
        if (count($resp)){
            return array('status'=>$err, 'str' => '', 'note'=>'', 'rslt'=>$resp);
        } else {
            return array('status'=>$err, 'str' => 'Blank result', 'note'=>$tquery, 'rslt'=>$resp);
        }
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>$err, 'str' => $db->error, 'note'=>$tquery, 'rslt'=>$resp);
    }
}

function sqlAddBind($jsobj, $logid="", $lognote="" ){
    $resp = [];
    $db = new mysqli($_ENV['DB_HOST'].":".$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME']);
    $db->set_charset("utf8");
    if ($db->connect_errno) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>$db->connect_errno, 'str' => $db->connect_error, 'note'=>$_ENV['DB_USER']."@".$_ENV['DB_HOST'].":".$_ENV['DB_PORT'], 'rslt'=>$resp);
        // exit();
    }
    $bindtm = date('Y-m-d H:i:s');
    $ok = true;
    if (isset($jsobj->tm)) {$bindtm = $jsobj->tm;}
    $vquery = "insert into vk_dcmbind (dcmcode, dbt, amnt, eq, dsc, bns, clientid, note, shop, tm, cshr) values ('$jsobj->dcm', '$jsobj->dbt', $jsobj->amnt, $jsobj->eq, $jsobj->dsc, $jsobj->bns, '$jsobj->clnt', '".$db->real_escape_string($jsobj->note)."', '".$post->shop."', '$bindtm', '$jsobj->cshr');";
    $rslt = $db->query($vquery);
    if((integer)$db->errno) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>(integer)$db->errno, 'str' => $db->error, 'note'=>$vquery, 'rslt'=>$resp);
    }
    $vquery = "";
    $vqval = "";
    $rslt1 = $db->query("select LAST_INSERT_ID();");
    $lid = $rslt1->fetch_row()[0];
    if ($lid!=0) {
        foreach ($jsobj->dcms as $dcm) {
            $vqval .= ($vqval!=""?",":"")." ($lid, '$dcm->dcm','$dcm->crn', '$dcm->dbt', '$dcm->cdt', $dcm->amnt, $dcm->eq, $dcm->dsc, $dcm->bns, '".$db->real_escape_string($dcm->note)."')";
        }
        if ($vqval!="") {
            $vquery = "insert into vk_dcm (pid, dcmcode, atclcode, dbt, cdt, amnt, eq, dsc, bns, note) values $vqval ;";
        }
        $rslt2 = $db->query($vquery);
        if ((integer)$db->errno) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
            return array('status'=>(integer)$db->errno, 'str' => $db->error, 'note'=>$vquery, 'rslt'=>$resp);
        } else {
        // api log
            $rslt = $db->query("replace into vk_dbg_func (id, tm, note) values ('v1/$logid', '".date("Y:m:d H:i:s")."', '$lognote') ;");
            return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>$resp);
        }
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>(integer)$db->errno, 'str' => $db->error, 'note'=>'select LAST_INSERT_ID()', 'rslt'=>$resp);
    }
}

?>