<?php
header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
require_once "./lib.php";
// $endpoints = array();
$endpoints_get = array();
$endpoints_post = array();

const QUERY_BALANCE_SSE =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
."where (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and (shop='CITY' or shop='FEYA' or shop='EKSV' or shop='BULK');";

const QUERY_OFFERS_SSE = "SELECT vk_offer_crnc.id as oid,  vk_currency.id as curid, chid, name, coalesce(vk_currency.qty,'1') qty, shop, bidask, amnt, price, tel, vk_offer_crnc.note as onote, tm, sortorder FROM vk_offer_crnc join vk_currency on(atclcode=chid) order by tm desc;";

const QUERY_RATES_SSE = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, "
."sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' order by sortorder;";
// SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' order by sortorder;

const QUERY_RATES_SSE2 = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, scode, cur.sname, case cur.qty when '' then 1 else cur.qty end as cqty, "
."sortorder, pricecode as prc, cur.domestic FROM vk_rate join (SELECT id as atclcode, chid, name, symb, qty, sortorder, domestic, coalesce(scode,'') scode, coalesce(sname,'') sname "
."FROM `vk_currency` left join vk_cur_sub on id=atclcode) as cur USING(atclcode, scode) where sortorder!='' order by sortorder, scode;";

/**
 * prints a default message if the API base path is queried.
 * @param array $requestData contains the parameters sent in the request, for this endpoint they are ignored.
 * @return void
 */
$endpoints_get["/"] = function (array $requestData): void {

    echo json_encode("Welcome to my GET API!");
};
$endpoints_post["/"] = function (array $requestData): void {

    echo json_encode("Welcome to my POST API!");
};

/**
 * prints a greeting message with the name specified in the $requestData["name"] item.
 * if the variable is empty a default name is used.
 * @param array $requestData this array must contain an item with key "name" 
 *                           if you want to display a custom name in the greeting.
 * @return void
 */
$endpoints_get["sayhello"] = function (array $requestData): void {

    if (!isset($requestData["name"])) {
        $requestData["name"] = "Misterious masked individual";
    }

    echo json_encode("hello! " . $requestData["name"]);
};
$endpoints_post["sayhello"] = function (array $requestData): void {

    if (!isset($requestData["name"])) {
        $requestData["name"] = "Misterious masked individual";
    }

    echo json_encode("hello! " . $requestData["name"]);
};

/**
 * prints a default message if the endpoint path does not exist.
 * @param array $requestData contains the parameters sent in the request, 
 *                           for this endpoint they are ignored.
 * @return void
 */
$endpoints_get["404"] = function ($requestData): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo json_encode(array('status'=>404, 'str' => ' 404 not found', 'note'=>"Endpoint GET " . $requestData["endpointName"] . " not found.", 'rslt'=>$resp));
    // echo json_encode("Endpoint GET" . $requestData["endpointName"] . " not found.");
};
$endpoints_post["404"] = function ($requestData): void {
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo json_encode(array('status'=>404, 'str' => ' 404 not found', 'note'=>"Endpoint POST " . $requestData["endpointName"] . " not found.", 'rslt'=>$resp));
};

$endpoints_get["offers"] = function ($requestData): void { 
    $reqid = isset($requestData["reqid"]) ? $requestData["reqid"] : "EMPTY";
    $shop = isset($requestData["shop"]) ? $requestData["shop"] : "";
    $flt = ($shop == "" ? "" : " WHERE shop='$shop'");
    $vquery = "";
    // log data
    // echo "replace into vk_dbg_func (id, tm, note) values ('".API_VERSION."/offers/$reqid', '".date("Y:m:d H:i:s")."', '') ;";
    sqlReq("replace into vk_dbg_func (id, tm, note) values ('".API_VERSION."/offers/$reqid', '".date("Y-m-d H:i:s")."', '') ;");
    if ($requestData["reqid"] == "ssedb") {
        echo json_encode(sqlReq_cache(QUERY_OFFERS_SSE, true));
    } else if ($requestData["reqid"] == "sse") {
            echo json_encode(sqlReq_cache(QUERY_OFFERS_SSE));
    } else if ($requestData["reqid"] == "sel") {
        $vquery = "SELECT vk_offer_crnc.id as oid,  vk_currency.id as curid, chid, name, coalesce(vk_currency.qty,'1') qty, shop, bidask, amnt, price, tel, vk_offer_crnc.note as onote, tm, sortorder FROM vk_offer_crnc join vk_currency on(atclcode=chid) $flt order by tm desc;";
        echo json_encode(sqlReq($vquery));
    }
};

$endpoints_get["archive"] = function ($requestData): void { 
    $reqid = isset($requestData["reqid"]) ? $requestData["reqid"] : "EMPTY";
    $cur = isset($requestData["cur"]) ? $requestData["cur"] : "840";
    $period = isset($requestData["period"]) ? $requestData["period"] : date("Y-m-d");
    $vquery = "";
    if ($requestData["reqid"] == "ratesAvrg") {
        // $vquery = "SELECT period, ba, sum(amnt), sum(eq), minrate, maxrate FROM `vk_daycurslt` WHERE acnt='3500' and atclcode='840' GROUP BY period, ba order by period;";
        $vquery = "SELECT b.period, sum(b.amnt) bamnt, sum(b.eq) beq, min(b.minrate) bmin, max(b.maxrate) bmax, sum(a.amnt) aamnt, sum(a.eq) aeq, min(a.minrate) amin, max(a.maxrate) amax "
        ." FROM vk_daycurslt b, vk_daycurslt a WHERE b.period=a.period and b.atclcode=a.atclcode and substr(b.period,1,".strlen($period).")<='$period' and substr(b.period,1,"
        .strlen($period).")>='".date_format(date_modify(date_create($period),"-1 month"),"Y-m-d")."' and b.ba='buy' and a.ba='sell' and b.acnt='3500' and b.atclcode='$cur' "
        ." GROUP BY b.period, b.ba order by b.period;";
        // echo "/offers/index.php qry=".$vquery;
        // exit();
    }
    echo json_encode(sqlReq($vquery));
};

$endpoints_get["currencies"] = function ($requestData): void { 
    $vquery = "";
    if ($requestData["reqid"] == "sel") { 
        $vquery =  "SELECT id, chid, name, symb, case qty when '' then '1' else qty end qty, sortorder so, domestic dmst FROM vk_currency where sortorder != '' order by sortorder;";  
    } else if ($requestData["reqid"] == "selsub") {
        $vquery = "SELECT atclcode, scode as id, sname FROM  vk_cur_sub  ORDER BY scode;";
    } else if ($requestData["reqid"] == "_selsub") {
        $vquery = "SELECT id, chid, name, symb, qty, sortorder, domestic, coalesce(scode,'') scode, coalesce(sname,'') sname FROM `vk_currency` left join vk_cur_sub on id=atclcode order by sortorder, scode;";
    }
    echo json_encode(sqlReq($vquery));
}; 

$endpoints_get["rates"] = function ($requestData): void {
    $vquery = "";
    sqlReq("replace into vk_dbg_func (id, tm, note) values ('".API_VERSION."/rates/".$requestData['reqid']."', '".date("Y-m-d H:i:s")."', '') ;");
    if ($requestData["reqid"] == "board") {
        $flt = "";
        $shop = isset($requestData["shop"]) ? $requestData["shop"] : "CITY";
        $pricecode = isset($requestData["pricecode"]) ? $requestData["pricecode"] : "";
        if (isset($shop)) { $flt .= ($flt == "") ? (" shop = '".$shop."'") : (" and shop = '".$shop."'"); }
        if (isset($pricecode)) { $flt .= ($flt == "") ? (" pricecode = '".$pricecode."'") : (" and pricecode = '".$pricecode."'"); }
            else { $flt .= ($flt == "") ? (" pricecode = '' OR pricecode IS NULL") : (" and (pricecode = '' OR pricecode IS NULL)"); }
        if ($flt != "") { $flt = " where $flt"; } 
        $vquery = "SELECT id, symb, chid, name, domestic, vk_currency.qty as cqty, coalesce(rr.qty,'1') as rqty, coalesce(rr.bid,'0') as rbid, coalesce(rr.bidtm,'') as rbidtm, coalesce(rr.ask,'0') as rask, coalesce(rr.asktm,'') as rasktm, "
        ."coalesce(br.qty,'1') as bqty, coalesce(br.bid,'0') as bbid, coalesce(br.bidtm,'') as bbidtm, coalesce(br.ask,'0') as bask, coalesce(br.asktm,'') as basktm, sortorder as so, coalesce(scode,'') scode, coalesce(sname,'') sname FROM vk_currency "
        ." left join vk_cur_sub on id=atclcode left join (select atclcode, qty, bid, bidtm, ask, asktm from vk_rate where shop='$shop' and (pricecode='' OR pricecode IS NULL)) as rr on(id=rr.atclcode) "
        ."left join (select atclcode, qty, bid, bidtm, ask, asktm from vk_rate where shop='BULK' and (pricecode='' OR pricecode IS NULL)) as br on(id=br.atclcode) "
        ."WHERE sortorder!='' and sortorder!='0' order by sortorder,chid";
        echo json_encode(sqlReq($vquery));
    } else if ($requestData["reqid"] == "ratebulk") {
        $flt = "";
        $shop = isset($requestData["shop"]) ? $requestData["shop"] : "";
        $pricecode = isset($requestData["pricecode"]) ? $requestData["pricecode"] : "";
        if (isset($shop)) { $flt .= ($flt == "") ? (" shop = '".$shop."'") : (" and shop = '".$shop."'"); }
        if (isset($pricecode)) { $flt .= ($flt == "") ? (" pricecode = '".$pricecode."'") : (" and pricecode = '".$pricecode."'"); }
            else { $flt .= ($flt == "") ? (" pricecode = '' OR pricecode IS NULL") : (" and (pricecode = '' OR pricecode IS NULL)"); }
        if ($flt != "") { $flt = " where $flt"; } 
        $vquery = "SELECT id, symb, chid, name, vk_currency.qty as cqty, rr.qty as rqty, rr.bid as rbid, rr.bidtm as rbidtm, rr.ask as rask, rr.asktm as rasktm, "
        ."coalesce(br.qty,'1') as bqty, coalesce(br.bid,'0') as bbid, coalesce(br.bidtm,'') as bbidtm, coalesce(br.ask,'0') as bask, coalesce(br.asktm,'') as basktm, sortorder as so FROM vk_currency "
        ."left join (select atclcode, qty, bid, bidtm, ask, asktm from vk_rate where shop='$shop' and (pricecode='' OR pricecode IS NULL)) as rr on(id=rr.atclcode) "
        ."left join (select atclcode, qty, bid, bidtm, ask, asktm from vk_rate where shop='$shop' and pricecode='bulk') as br on(id=br.atclcode) "
        ."WHERE sortorder!='' and sortorder!='0' order by sortorder,chid";
        echo json_encode(sqlReq($vquery));
    } else if ($requestData["reqid"] == "ssedb") {      
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE2, true));
    } else if ($requestData["reqid"] == "sse") {      
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE));
    } else if ($requestData["reqid"] == "sse2") { 
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE2));
    } else if ($requestData["reqid"] == "forex") {        
        $vquery = "SELECT 'USDUAH,EURUSD,USDPLN,USDCZK,GBPUSD,USDCAD,USDCHF,AUDUSD,USDSEK,USDNOK,USDDKK,USDHUF,USDBGN,USDRON,USDTRY,USDCNY,USDJPY' as pair, "
        ."'AUD=3,BGN=12,CAD=2,CHF=0.8,CNY=20,CZK=3.1,DKK=3.5,EUR=0.5,GBP=1.4,HUF=4.5,JPY=12,NOK=4.5,PLN=0.5,RON=10,RUB=1.75,SEK=2.5,TRY=7' as bid, "
        ."'AUD=0.2,BGN=5,CAD=0.5,CHF=-0.2,CNY=5,CZK=0,DKK=2,EUR=0.3,GBP=0.3,HUF=1,JPY=5,NOK=2,PLN=0,RON=3,RUB=0.5,SEK=1,TRY=2' as ask;";
        echo json_encode(sqlReq($vquery));
    }
};

$endpoints_post["accounts"] = function ($post): void { 
    $vquery = "";
    if ($post->reqid == "sel") {
        $vacnt = isset($post->acnt) ?  $post->acnt : "3000";
        $term = isset($post->term) ? $post->term : "";
        $vfilter = "1";
        if ($vacnt != "3003" && (substr($vacnt,0,2)!="35")){
            // $tmp ="$acnt here !!!";
            if ($payload->role != "owner") { $vfilter = "code='$term'"; }
        }
        $vquery =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
        ."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
        ."where substr(acntno,1,".strlen($vacnt).")='$vacnt' and (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and $vfilter order by coalesce(vk_currency.sortorder,''),vk_shop.sortorder,substr(acntno,1,4),tm desc;";
        // echo "/accounts/index.php qry=".$vquery;
        // exit();
        echo json_encode(sqlReq($vquery, "accounts/$post->reqid"));
    } else if ($post->reqid == "ssedb") {      
        echo json_encode(sqlReq_cache(QUERY_BALANCE_SSE, true));
    } else if ($post->reqid == "sse") {      
        echo json_encode(sqlReq_cache(QUERY_BALANCE_SSE));
    } else if ($post->reqid == "selkn") {      
        $vquery =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt, turndbt, turncdt, tm, acntno, '' as scancode, coalesce(vk_currency.sortorder,'0') cuso, coalesce(vk_shop.sortorder,'99') shso "
        ."from vk_acntst join vk_currency on(id=atclcode) left join vk_shop on(vk_acntst.shop=vk_shop.code) "
        ."where (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and (shop='CITY' or shop='FEYA' or shop='EKSV' or shop='BULK');";
        echo json_encode(sqlReq($vquery, "accounts/$post->reqid"));
    } else if ($post->reqid == "upd") {      
        $val = "";
        $vquery = "REPLACE INTO vk_acntst (acntno, atclcode, amnt, turndbt, turncdt, shop, tm) VALUES ";
        foreach ($post->data as $row) {
            $val .= ($val!=""?",":"")."('$row->acntno','$row->articleid','$row->amnt','$row->turndbt','$row->turncdt','$post->shop','$row->tm')";
        }
        $vquery .= $val.";";
        echo json_encode(sqlReq($vquery, "accounts/$post->reqid"));
    } else if ($reqid == "del") {      
         if (isset($post->shop)) {
            $vquery = "delete from vk_acntst where shop='".$post->shop."'";
        }
        echo json_encode(sqlReq($vquery, "accounts/$post->reqid"));
    }
}; 

$endpoints_post["auth"] = function ($post): void { 
    // print_r($post);
    $db = dbconnect();
    if ($db->connect_error) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        echo json_encode(array('token' => '', 'errstr'=>$db->connect_error));
        exit();
    }
    $sql="SELECT user_pwd, user_role, salt FROM vk_users WHERE user_role!='' and user_login = '".$post->usr."'";
    $rslt = $db->query($sql);
    $crnt_user = $rslt->fetch_row();
    if (!$crnt_user) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo json_encode(array('token' => '', 'errstr'=>'Authorization error.'));
        exit();
    }
    
    if (!password_verify(($post->psw.$crnt_user[2]),$crnt_user[0])) { 
        $rslt = $db->query("update vk_users set lastrequest=CURRENT_TIMESTAMP, note='$retnote' where user_login = '".$post->usr."'");
        header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
        echo json_encode(array('token' => '', 'errstr'=>'Authorization error.'));
        exit();
    }
    $cus = "";
    if (($crnt_user[1] == "owner")){
        $cus = "BULK";
    } else {
        if ($post->usr == "ownerwife"){
            $cus = "FEYA";
        } else {
            $cus = $post->usr;
        }
    }
    // $cus = (($crnt_user[1] == "owner") ? "BULK" : $post->usr);
    $vheader = '{ "alg": "HS256", "typ": "JWT"}';
    // term - login terminal
    // crntuser - deprecated
    // print_r($vheader);
    $vpayload = json_encode(array('crntuser' => $cus, 'term'=> $cus, 'role' => $crnt_user[1], 'user' => $post->usr));
    $unsignedToken = base64_encode($vheader) . '.' . base64_encode($vpayload);
    $signature = hash_hmac('sha256',$unsignedToken, $_ENV["SHA256_KEY"]);
    echo json_encode(array('token' => $unsignedToken.'.'.$signature)); 
};

$endpoints_post["dcms"] = function ($post): void { 
    $vquery = "";
    // print_r($post);
    if ($post->reqid == "sel") {
        // $postdata = "acnt=".$post->acnt." cur=".$post->cur." shop=".$post->shop;
        $flt = "";
        $offset = "";
        $limit = "";
        if (isset($post->acnt)) { $flt .= ($flt == "") ? (" (cdt = '".$post->acnt."' or vk_dcm.dbt = '".$post->acnt."')") : (" and (cdt = '".$post->acnt."' or vk_dcm.dbt = '".$post->acnt."')"); }
        if (isset($post->cur)) { $flt .= ($flt == "") ? (" atclcode = '".$post->cur."'") : (" and atclcode = '".$post->cur."'"); }
        if (isset($post->shop)) { $flt .= ($flt == "") ? (" shop = '".$post->shop."'") : (" and shop = '".$post->shop."'"); }
        if (isset($post->from)) { $flt .= ($flt == "") ? (" tm >= '".$post->from."'") : (" and tm >= '".$post->from."'"); }
        if (isset($post->to)) { $flt .= ($flt == "") ? (" SUBSTRING(tm,0,10) <= '".$post->to."'") : (" and SUBSTRING(tm,0,10) <= '".$post->to."'"); }
        if ($flt != "") { $flt = " where $flt"; } 
        if (isset($post->offset)) { $offset = "offset $offset"; } else { $offset = "offset 0"; }
        if (isset($post->limit)) { $limit = "limit $post->limit"; }

        $vquery =  "SELECT vk_dcmbind.dcmcode as bcode, vk_dcmbind.dbt as bacnt, vk_dcmbind.amnt as bamnt, vk_dcmbind.eq as beq, vk_dcmbind.dsc as bdsc, vk_dcmbind.bns as bbns,"
        ." clientid, tm, shop, cshr, vk_dcm.id, pid, vk_dcm.dcmcode as dcmcode, vk_dcm.amnt as amnt, vk_dcm.eq as eq, vk_dcm.dsc as dsc, vk_dcm.bns as bns, vk_dcm.note as note,"
        ." vk_dcm.atclcode as atclcode, vk_dcm.dbt, cdt from vk_dcmbind join vk_dcm on (vk_dcmbind.id=pid) $flt order by tm desc, pid desc $limit;";
        echo json_encode( sqlReq($vquery, "dcms/$post->reqid"));

    } else if ($post->reqid == "upd") {   
        // {"id":"dcmbind","dcm":"check","dbt":"3000","cdt":"","amnt":"6.00","eq":"-6.00","dsc":"0.00","bns":"0.00","note":"","clnt":"",
        //     "dcms":[{"dcm":"trade:buy","dbt":"3000","cdt":"3500","crn":"840","amnt":"10.00","eq":"394.00","dsc":"0.00","bns":"0.00","note":"USD","retfor":""},
        //     {"dcm":"trade:sell","dbt":"3000","cdt":"3500","crn":"840","amnt":"-10.00","eq":"-400.00","dsc":"0.00","bns":"0.00","note":"USD","retfor":""}],
        //     "tm":"2024-05-07 22:49:00","cshr":""} 
        echo json_encode( sqlAddBind($post->data,"dcms/$post->reqid"));
    }
}; 

$endpoints_post["offers"] = function ($post): void { 
    $vquery = "";
    if ($post->reqid == "upd") {   
        // {"reqid":"upd","oid":"3501","knt":"BULK","cur":"EUR","ba":"ask","amnt":"121","rate":"37.12","note":"qwe234","tm":"2024-07-18T17:49:16.535Z"}
        // {"reqid","ofid","cur","ba","amnt","price","tel","note","tm"}
        if ($post->ofid != "" && ($post->amnt == "" || $post->price == "")) {   // delete
            $vquery = "DELETE FROM vk_offer_crnc WHERE id = '$post->ofid' ";
        } else if ($post->ofid != "") {        // update
            $vquery = "UPDATE vk_offer_crnc SET shop='$post->shop',atclcode='$post->cur',bidask='$post->ba',amnt='$post->amnt',price='$post->price',tel='$post->tel',note='$post->note',tm='$post->tm' WHERE id = '$post->ofid' ";
        } else if ($post->ofid == "") {        // insert
            $vquery = "INSERT INTO vk_offer_crnc(shop, atclcode, bidask, amnt, price, tel, note, tm) VALUES ('$post->shop','$post->cur','$post->ba','$post->amnt','$post->price','$post->tel','$post->note','$post->tm')";
        }
    }
    echo json_encode( sqlReq($vquery, "offers/$post->reqid"));
}; 

$endpoints_post["rates"] = function ($post): void {
    // echo "111 ".$post->reqid;
    // $post = json_decode($dd);
    $vquery = "";
    if ($post->reqid == "sel") {
        // $post = json_decode($requestData['data']);
        // echo $post->reqid;
        $shop = isset($post->shop) ? $post->shop : "";
        $pricecode = isset($post->pricecode) ? $post->pricecode : "";
        $flt = "";
        if ($shop != "") { $flt .= ($flt == "") ? (" shop = '".$shop."'") : (" and shop = '".$shop."'"); }
        if ($pricecode != "") { $flt .= ($flt == "") ? (" pricecode = '".$pricecode."'") : (" and pricecode = '".$pricecode."'"); }
            // else { $flt .= ($flt == "") ? (" pricecode = '' OR pricecode IS NULL") : (" and pricecode = '' OR pricecode IS NULL"); }
        if ($flt != "") { $flt = " and $flt"; } 
        $vquery = "SELECT atclcode, vk_rate.qty as rqty, bid, ask, bidtm, asktm, shop, chid, name, case vk_currency.qty when '' then 1 else vk_currency.qty end as cqty, "
        ."sortorder, pricecode as prc FROM vk_rate join vk_currency on (atclcode=id) where sortorder!='' $flt order by sortorder;";
        // echo "/rates qry=".$vquery;
        // exit();
        echo json_encode(sqlReq($vquery));
    } else if ($post->reqid == "ssedb") {      
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE2, true));
    } else if ($post->reqid == "sse") {      
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE));
    } else if ($post->reqid == "sse2") { 
        echo json_encode(sqlReq_cache(QUERY_RATES_SSE2));
    } else if ($post->reqid == "upd") {      
        // $post = json_decode($requestData['data']);
        echo json_encode( sqlUpdRate($post->rates,"rates/$post->reqid"));
        // $val = "";
        // $vquery = "REPLACE INTO vk_rate (shop, atclcode, scode, pricecode, qty, bid, ask, bidtm, asktm) VALUES ";
        // foreach ($post->rates as $rate) {
        //     $val .= ($val!=""?",":"")."('$rate->shop','$rate->atclcode','$rate->scode','$rate->pricecode','".($rate->qty==""?"1":$rate->qty)."','".($rate->bid==""?"0":$rate->bid)."','".($rate->ask==""?"0":$rate->ask)."','$rate->tm','$rate->tm')";
        // }
        // $vquery .= $val.";";
        // echo json_encode(sqlReq($vquery));
    }


}; 

$endpoints_post["reports"] = function ($post): void {
    $vquery = "";
    $vfilter = "1";
    $kant = $post->payload->term;
    // $payload = json_decode(base64_decode($tarr[1]));
    if ($post->reqid == "acnt") {    // NOT READY
        $vacnt = isset($post->code) ?  $post->code : "3000";
        if ($vacnt != "3003" && (substr($vacnt,0,2)!="35")){
            // $tmp ="$acnt here !!!";
            if ($post->payload->role != "owner") { $vfilter = "code='$kant'"; }
        }
        if (substr($vacnt,0,2)=="30") {
            $vquery =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, amnt as total, turndbt as income, turncdt as outcome, tm, acntno "
            ."from vk_acntst left join vk_currency on(id=atclcode) join vk_shop on(vk_acntst.shop=vk_shop.code) "
            ."where substr(acntno,1,".strlen($vacnt).")='$vacnt' and permit and (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and $vfilter order by coalesce(vk_currency.sortorder,0),vk_shop.sortorder,substr(acntno,1,4),tm desc;";
        } else {
            $vquery =  "select coalesce(id,'') as id, coalesce(chid,'UAH') as chid, shop, 0-amnt as total, turndbt as outcome, turncdt as income, tm, acntno "
            ."from vk_acntst left join vk_currency on(id=atclcode) join vk_shop on(vk_acntst.shop=vk_shop.code) "
            ."where substr(acntno,1,".strlen($vacnt).")='$vacnt' and permit and (abs(amnt)>0.0000001 or substr(now(),1,10)=substr(tm,1,10)) and $vfilter order by coalesce(vk_currency.sortorder,0),vk_shop.sortorder,substr(acntno,1,4),tm desc;";
        }
    
    } else if ($post->reqid == "reprate"){
        $tm = (isset($post->code) && ($post->code != "")) ? $post->code : date('Y-m-d');
        $vquery =
        "select substr(tm,1,10) as period, vk_currency.sortorder as curso, chid, coalesce(vk_shop.sortorder,99) as shopso, shop, "
        ."sum(case vk_dcm.dcmcode when 'trade:buy' then vk_dcm.amnt else 0 end) as buyamnt, sum(case vk_dcm.dcmcode when 'trade:buy' then vk_dcm.eq else 0 end) as buyeq, "
        ."sum(case vk_dcm.dcmcode when 'trade:sell' then -1*vk_dcm.amnt else 0 end) as sellamnt, sum(case vk_dcm.dcmcode when 'trade:sell' then -1*vk_dcm.eq else 0 end) as selleq, vk_dcm.cdt as acnt "
        ."from vk_dcm join vk_dcmbind on(vk_dcm.pid=vk_dcmbind.id) join vk_currency on (vk_dcm.atclcode=vk_currency.id) left join vk_shop on(vk_dcmbind.shop=vk_shop.code) "
        ."where substr(vk_dcm.cdt,1,3)='350' and substr(vk_dcm.dcmcode,1,6)='trade:' and substr(tm,1,".strlen($tm).")='$tm' group by period desc, curso,acnt, chid, shopso, shop;";
        
    } else if ($post->reqid == "kntprofit"){
        $vfilter = "";
        if ($post->shop != ""){
            $vfilter = "and shop='$post->shop'";
        }
        $vquery = "SELECT acnt as gr0, shop as gr1, chid as code, period, CONCAT('/',acnt,'/',vk_shop.sortorder,'/',coalesce(vk_currency.sortorder,'99')) as so, amnt "
        ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
        ."WHERE period >= '".$post->from."' and period <= '".$post->to."' and vk_mnrslt2.code = 'profit' $vfilter order by so;";
        
    } else if ($post->reqid == "curprofit"){
        $vfilter = "";
        if ($post->shop != ""){
            $vfilter = "and shop='$post->shop'";
        }
        $vquery = "SELECT acnt as gr0, chid as gr1, shop as code, period, CONCAT('/',acnt,'/',coalesce(vk_currency.sortorder,'99'),'/',vk_shop.sortorder) as so, amnt "
        ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
        ."WHERE period >= '".$post->from."' and period <= '".$post->to."' and vk_mnrslt2.code = 'profit' $vfilter order by so;";
        
    } else if ($post->reqid == "cshprofit"){
        $vfilter = "";
        if ($post->shop != ""){
            $vfilter = "and shop='$post->shop'";
        }
        $vquery = "SELECT cashier as gr0, chid as gr1, shop as code, period, CONCAT('/',cashier,'/',coalesce(vk_currency.sortorder,'99'),'/',vk_shop.sortorder) as so, amnt "
        ."FROM vk_mnrslt2 join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
        ."WHERE period >= '".$post->from."' and period <= '".$post->to."' and vk_mnrslt2.code = 'profit' and acnt='3500' $vfilter order by so;";
    } else if ($post->reqid == "chartprofit") {  
        // $fr = isset($_POST["from"]) ? $_POST["from"] : date_format(date_create(), 'Y-m');
        // $to = isset($_POST["to"]) ? $_POST["to"] : date_format(date_create(), 'Y-m');
        $vquery = "SELECT acnt, shop, cashier, chid, period, vk_mnrslt2.amnt total, vk_wage.amnt wage, vk_currency.sortorder cso, vk_shop.sortorder sso "
        ." FROM vk_mnrslt2 left join vk_wage using(period,cashier) join vk_shop on (vk_mnrslt2.shop=vk_shop.code) join vk_currency on(vk_mnrslt2.atclcode=vk_currency.id) "
        ." WHERE period >= '".$post->from."' and period <= '".$post->to."' and vk_mnrslt2.code = 'profit' and cashier!=''  and acnt='3500';";
    }
    
    echo json_encode( sqlReq($vquery, "reports/$post->reqid"));
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



?>
