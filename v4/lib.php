<?php
define("API_VERSION", "v3");
define("PROJECT_ROOT_PATH", __DIR__ . "/");
define("PATH_TO_MEMCACHED", "/home/g32062/.memcached/memcached.sock"); // for deployment
// define("PATH_TO_MEMCACHED", "");        // for local testing
define("MEMCACHED_TTL", 8);
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
    return $db1;
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

/**
 * 
 */
function sqlReq_cache($vquery, $force_DB=false){
    // $resp = [];
    if (class_exists("Memcached") && (PATH_TO_MEMCACHED != "")) {
        $mem = new Memcached();
        $mem->addServer(PATH_TO_MEMCACHED, 11211);
        $key = "KEY" . md5($vquery);
        $resp = $mem->get($key);
        if ($force_DB || !$resp) {
            $resp = sqlReq($vquery);
            if (!($mem->set($key, $resp, MEMCACHED_TTL))) {
                $resp['note'] .= ": Memcached ERROR ".$mem->getResultCode()."\n";
            };
            $resp['str'] .= "DataBase Zkey=$key\n";
            return $resp;
        } else {
            $resp['str'] .= "Memcached Ykey=$key\n";
            return $resp;
        }
    } else {
        return sqlReq($vquery);
    }
}

/*function sqlReq_mem($vquery, $mem, $logid="", $lognote="" ){
    // $resp = [];
    $key = "KEY" . md5($vquery);
    $note = "";
    $resp = $mem->get($key);
    if (!$resp) {
        $resp = sqlReq($vquery,$logid, $lognote);
        if (!($mem->set($key, $resp, MEMCACHED_TTL))) {
            $resp['note'] .= ": Memcached ERROR ".$mem->getResultCode()."\n";
        };
        $resp['str'] .= ": from DataBase key=$key\n";
        return $resp;
    } else {
        $resp['str'] .= ": from Memcached key=$key\n";
        return $resp;
        // return array('status'=>0, 'str' => $note, 'note'=> '', 'rslt'=>$resp);
    }
} */

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
            // $rslt = $db->query("replace into vk_dbg_func (id, tm, note) values ('v1/$logid', '".date("Y:m:d H:i:s")."', '$lognote') ;");
            return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>$resp);
        }
    } else {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>(integer)$db->errno, 'str' => $db->error, 'note'=>'select LAST_INSERT_ID()', 'rslt'=>$resp);
    }
}

function sqlUpdRate($jsobj, $logid="", $lognote="" ){
    $resp = [];
    // header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
    // return array('status'=>66, 'str' => 'TEST', 'note'=>'TEST_', 'rslt'=>$resp);



    $db = new mysqli($_ENV['DB_HOST'].":".$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $_ENV['DB_NAME']);
    $db->set_charset("utf8");
    if ($db->connect_errno) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        return array('status'=>$db->connect_errno, 'str' => $db->connect_error, 'note'=>$_ENV['DB_USER']."@".$_ENV['DB_HOST'].":".$_ENV['DB_PORT'], 'rslt'=>$resp);
        // exit();
    }
    $ok = true;
    $respnote = "";
    $updsql = "";
    foreach ($jsobj as $rate) {
        $whereclause = "WHERE shop='$rate->shop' and atclcode='$rate->atclcode' and scode='$rate->scode' and pricecode='$rate->pricecode'";
        // delete rate when emty bid&ask
        if ((floatval($rate->bid)==0) && (floatval($rate->ask)==0)) {
            $delsql = "DELETE FROM vk_rate $whereclause;";
            sqlRec($delsql);
            continue;
        }
        $tm = date('Y-m-d H:i:s');
        $ok = true;
        if (isset($rate->tm)) {$tm = $rate->tm;}
        // update lastbid || lastask if necessary
        $selsql = "SELECT bid, bidtm, ask, asktm FROM vk_rate $whereclause;";
        $rslt = $db->query($selsql);
        $updsqllast = "";
        $newrate = true;
        if ($row = $rslt->fetch_row()) {
            $newrate = false;
            if ($rate->bid != $row->bid){
                $updsqllast .= "lastbid='$row[0]', lastbidtm='$row[1]'";
            }
            if ($rate->ask != $row->ask){
                $updsqllast .= ($updsqllast !=''?", ":"") . "lastask='$row[2]', lastasktm='$row[3]'";
            }
            if ($updsqllast != ""){
                sqlReq("UPDATE vk_rate SET $updsqllast $whereclause;"); 
            }
        }
        $updsql = "";
        if ($newrate){
            $updsql = "INSERT INTO vk_rate (shop, atclcode, scode, pricecode, qty, bid, ask, bidtm, asktm) VALUES ";
            $updsql .= "('$rate->shop','$rate->atclcode','$rate->scode','$rate->pricecode','".($rate->qty==""?"1":$rate->qty)."','".($rate->bid==""?"0":$rate->bid)."','".($rate->ask==""?"0":$rate->ask)."','$rate->tm','$rate->tm')";
        } else {
            $updsql = "UPDATE vk_rate SET bid='".$rate->bid."', bidtm='$tm', ask='".$rate->ask."', asktm='$tm' $whereclause;";
        }
        // don't error manager
        $resp = sqlReq($updsql);
        if ($resp['status']) {
            $ok &= false;
            $respnote .= $resp['note'];
        }
    }
    if ($ok){
        return array('status'=>0, 'str' => '', 'note'=>'', 'rslt'=>[]);
    } else {
        return array('status'=>2, 'str' => 'Batch update error', 'note'=>$respnote, 'rslt'=>[]);
    }

}
?>