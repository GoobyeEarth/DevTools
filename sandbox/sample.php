<?php
require_once("CM/CMSmarty.class.php");
require_once("CM/CMHttpUtil.php");
require_once("CM/CMConst.php");
require_once("CM/CMStringUtility.php");
require_once("CM/CMPointUtil.php");
require_once("CM/CMMailUtil.php");
require_once("CM/CMPaletteMail.class.php");
require_once("DB/DBCon.class.php");
require_once("DB/DBSqlWhere.class.php");
require_once("TL/TLrehDB.class.php");
require_once("TL/TLreDB.class.php");
require_once("TL/TLtaDB.class.php");
require_once("TL/TLrpDB.class.php");
require_once("TL/TLrhmDB.class.php");
require_once("JN/JNcommonDB.class.php");
require_once("researchUtiles.php");
require_once("XML/XMLResearchReader.class.php");
require_once("LANG/LANGAdmCode.php");

use psr\Services\Admin\MonitorSampling\AddResearchHaishinService as MonitorSamplingService;
use psr\Database\Database;
use Illuminate\Database\Capsule\Manager as DB;
use psr\Lib\Intage\ClientDetector;
use psr\Lib\Intage\Research;
use psr\Lib\Intage\ResearchHaishin\ViewControlParameters;
use psr\Util\ClientSetting;
use psr\Lib\Intage\ResearchOptions;
use psr\Lib\Intage\ResearchHaishin\DefaultEndPlanDateTime;

// セッション開始
session_name(kSESSION_NAME_ADMIN_LOGIN);
session_start();

$page_id = "research";    // 権限のためのページID
$function_id = "1";            // 権限のための機能I
$op_id = $_SESSION["op_id"];    // オペレータIDの取得
$cl_id = $_SESSION["cl_id"];    // クライアントIDの取得
$cl_code = $_SESSION["cl_code"];    // クライアントコードの取得

// セッション切れチェック
if ($_SESSION['id'] == "") {
    print "Session time out. Please Login again.";
    exit;
}

// 権限チェック		mod_igari 080328　アクセス権の仕掛けを変更
// 参照定数が変更 mod hosoya 2011.06.16
// $kinou_id = kKINOU_ID_RESEARCH;
$kinou_id = kKINOU_ID_RESEARCH_HAISHIN;
if ($_SESSION["access_privilege"][$kinou_id] != kACCESS_PRIVILEGE_READWRITE) {
    //OutputErrorForPopup( アクセス権がありません。 );
    print "You can't access on this server";
    exit;
}
// add_igari 2011.01.28
// クライアント設定を読み込む
$rc = util_LoadClientSetting($_SESSION["cl_code"]);
if ($rc == false) {
    print "System Error";
    log_error("cannot read client setting [" . $_SESSION["cl_code"] . "]", __FILE__);
    exit;
}

// *** パラメータ取得 ***
$re_id = http_getGET("re_id");
$below_point_allow_flg = http_getGET('below_point_allow_flg');

$dbCon = new DBCon();
if (!$dbCon->connect(kClientDBName))    // mod_igari 2011.01.28
{
    print "Failed to connect DB";
    log_error("db connect error. dbSettingName=[" . kClientDBName . "]", __FILE__);
    exit;
}

// リサーチ配信マスタ //add murakami 2013.09.13 #7398
if (!$research_haishin_state = GetMasterVal('research_haishin_state')) {
    OutputError($LangBox[$_SESSION["lang_c"]]["common_error"]["73"]);
    exit();
}

$dbSqlWhere = new DBSqlWhere();
$rehDB = new TLrehDB();
$taDB = new TLtaDB();
$rpDB = new TLrpDB();
$reDB = new TLreDB();

// リサーチを取得
$reRowAry = $reDB->selectByID_ForUpdate($dbCon, $re_id);

if ((is_array($reRowAry) == false) || (count($reRowAry) == 0))    // みつからなかった？
{
    log_error("select research error.", __FILE__);
    print "Failed to select research.";
    $dbCon->close();
    exit;
}
$reRow = $reRowAry[0];

$dbCon->beginTran();

// リサーチの一番新しい配信の番号を取得
$dbSqlWhere->clear();
$dbSqlWhere->addItem_Num("reh_re_id", "=", $re_id, "and");
$dbSqlWhere->addOrder_Desc("reh_no");
$dbSqlWhere->addLimit(1);
$rehRowAry = $rehDB->selectAny($dbCon, $dbSqlWhere);
if (is_array($rehRowAry) == false) {
    log_error("select research_haishin error.", __FILE__);
    print "Failed to select research_haishin records.";
    $dbCon->rollback();
    $dbCon->close();
    exit;
}
if (count($rehRowAry) > 0) {
    $reh_no_new = $rehRowAry[0]["reh_no"] + 1;
    $reh_id_src = $rehRowAry[0]["reh_id"];
} else {
    $reh_no_new = 1;
    $reh_id_src = "";
}

// オープンの場合は、すでにリサーチ配信があればエラー
// Scoopトラッキングの場合も、リサーチ配信は１つだけ
if ($reRow["re_rtm_id"] == kResearchType_Open
    || $reRow["re_rtm_id"] == kResearchType_OpenOut
    || $reRow["re_rtm_id"] == kResearchType_ScoopTracking    // #11154
) {
    if (count($rehRowAry)) {
        goto output;
    }
}

$datetime = getSystemDatetime($dbCon);

// クローズド、パレット、外部連携の場合は準備中
if ($reRow["re_rtm_id"] == kResearchType_Monitor ||
    $reRow["re_rtm_id"] == kResearchType_Palette ||
    $reRow["re_rtm_id"] == kResearchType_MonitorOut ||
    $reRow["re_rtm_id"] == kResearchType_PaletteOut ||    // add_igari 2011.05.19
    $reRow["re_rtm_id"] == kResearchType_ScoopTracking    // #11151
) {
    $reh_status = 1;
    $reh_setting_status = 100; //add murakami 2013.09.20 #7398
} // それ以外は、即座に配信待ち
else {
    $reh_status = 2;
    $reh_setting_status = 300; //add murakami 2013.09.20 #7398
}

//すべて終了後に追加された場合のステータス変更 add murakami 2013.11.01 #7888 
if ($reRow["re_rtm_id"] == kResearchType_Monitor
    || $reRow["re_rtm_id"] == kResearchType_MonitorOut
    || $reRow["re_rtm_id"] == kResearchType_ScoopTracking    // #11151
) {
    if ($reRow["re_setting_status"] == 900) {
        $reRow['re_setting_status'] = 800;
        $dbRes = $reDB->update($dbCon, $reRow);    // 更新
        if (!$dbRes) {
            log_error("update research error.", __FILE__);
            $dbCon->rollback();
            $dbCon->close();
            exit;
        }
    }
}
log_error('testtest: ');
// リサーチ配信を作成
$rehDB = new TLrehDB();
$rehRow = $rehDB->createNewRow();
$rehRow["reh_id"] = $rehDB->getNextID_seq($dbCon);
$rehRow["reh_re_id"] = $re_id;
$rehRow["reh_no"] = $reh_no_new;
$rehRow["reh_ta_id"] = 0.;
$rehRow["reh_status"] = $reh_status;
$rehRow["reh_setting_status"] = $reh_setting_status; //add murakami 2013.09.20 #7398

// 混在配信対応としてreh_typeにre_rtm_idを入れる #9330 nagaoka
$rehRow["reh_type"] = $reRow["re_rtm_id"];

// パレットの場合
if ($reRow["re_rtm_id"] == kResearchType_Palette ||
    $reRow["re_rtm_id"] == kResearchType_PaletteOut        // add_igari 2011.05.19
) {
    // 開始日時は空で即時配信、終了日時は指定不可
    $rehRow["reh_start_plan_datetime"] = "";
    $rehRow["reh_end_plan_datetime"] = "";
} else {
    // 開始予定日、終了予定日に変更	mod_igari 2011.02.23
    $rehRow["reh_start_plan_datetime"] = $datetime;    // 現在日時
    if (ClientDetector::isIntage($_SESSION["cl_code"])) {
        $rehRow["reh_end_plan_datetime"] = DefaultEndPlanDateTime::calculate($_SESSION['timezone']);
    } else {
        $rehRow["reh_end_plan_datetime"] = date("YmdHis", strtotime("+1 month")) . "000";    // 1ヶ月後
    }
}
$rehRow["reh_re_id"] = $re_id;
$rehRow["reh_create_datetime"] = $datetime;
$rehRow["reh_update_datetime"] = $datetime;
$dbRes = $rehDB->insert($dbCon, $rehRow);
if ($dbRes === false) {
    log_error("insert research_haishin error.", __FILE__);
    print "Failed to insert research haishin record.";
    $dbCon->rollback();
    $dbCon->close();
    exit;
}
$reh_id_dst = $rehRow["reh_id"];
$intage_research_options = new ResearchOptions();
// クローズド、パレット、外部連携なら、ポイント関連の処理を実行
if ($reRow["re_rtm_id"] == kResearchType_Monitor ||
    $reRow["re_rtm_id"] == kResearchType_Palette ||
    $reRow["re_rtm_id"] == kResearchType_MonitorOut ||
    $reRow["re_rtm_id"] == kResearchType_PaletteOut ||    // add_igari 2011.05.19
    ClientDetector::isIntage($reRow["re_cl_code"]) && $intage_research_options->useAladinUseDbCon($dbCon, $re_id)
) {
    // *** リサーチポイントレコードをセットアップ ***

    // ひとつ前のリサーチ配信のポイントをコピー
    if ($reh_id_src != "") {
        $rc = pntutil_CopyResearchPointRecord($dbCon, $reh_id_src, $reh_id_dst);
        if ($rc == false) {
            log_error("pntutil_CopyResearchPointRecord error.", __FILE__);
            print "Failed to copy research point record";
            $dbCon->rollback();
            $dbCon->close();
            exit;
        }
    }

    // XMLオブジェクトを取得
    $dir = getTemplateAdminResearchDir($reRow["re_cl_code"], $reRow["re_no"]);
    $xmlFilePath = $dir . kENQUETE_XML_NAME;
    $xml = new XMLResearchReader();
    $xml->readXML($xmlFilePath);

    // リサーチポイントをセットアップ
    $re_country = $reRow["re_country"];
    //引数に調査実施国を追加 add tominaga 20131022
    $rc = pntutil_SetupResearchPointRecord($dbCon, $xml, $re_id, $re_country);
    if ($rc == false) {
        log_error("pntutil_SetupResearchPointRecord error.", __FILE__);
        print "Failed to set re_point";
        $dbCon->rollback();
        $dbCon->close();
        exit;
    }
}

//================================================================
// クローズドなら、メール配信関連の処理を実行
// add_kakazu 2011.02.16
//================================================================
if ($reRow["re_rtm_id"] == kResearchType_Monitor
    || $reRow["re_rtm_id"] == kResearchType_MonitorOut
    || $reRow["re_rtm_id"] == kResearchType_ScoopTracking    // #11151
) {
    // added by chrono system k.uejima 2012.02.24
    if ($cl_code == "026") {
        // メールテンプレートを取得 add_nagaoka 2013.03.01
        $rc = pmlutil_SetupResearchMailRecordBpost($dbCon, $rehRow["reh_id"], $datetime, $reRow["re_country"]);
        if ($rc == false) {
            log_error("pmlutil_SetupResearchMailRecord error.", __FILE__);
            print "Failed to set send mail";
            $dbCon->rollback();
            $dbCon->close();
            exit;
        }

        // Scoopトラッキングの場合は、対象媒体のメールレコードの配信フラグを1にセットしておく	#11151
        if ($reRow["re_rtm_id"] == kResearchType_ScoopTracking) {
            // 対象媒体
            $targetRedIDAry = array(
                16,    // ECナビ
            );

            // メール配信テーブル更新
            $pmhbDB = new TLpmhbDB();

            // 配信フラグを立てるWHERE
            $dbSqlWhere->clear();
            $dbSqlWhere->addItem_Str("pmh_reh_no", "=", $rehRow["reh_id"], "and");
            $dbSqlWhere->addItem_kakko();

            foreach ($targetRedIDAry as $key => $val) {
                $dbSqlWhere->addItem_Num("pmh_red_id", "=", $val, "or");
            }

            $dbSqlWhere->addItem_kakkotoji("and");

            // データセット
            $row['pmh_haishin_flg'] = array("type" => "num", "value" => 1);
            $row['pmh_update_datetime'] = array("type" => "str", "value" => getSystemDatetime($dbCon));

            // 更新
            $dbRes = $pmhbDB->updateFree($dbCon, $row, $dbSqlWhere);
        }
    } else {
        // メールテンプレートを取得
        $rc = cmmailutil_SetupResearchMailRecord($dbCon, $rehRow["reh_id"], $datetime);
        if ($rc == false) {
            log_error("cmmailutil_SetupResearchMailRecord error.", __FILE__);
            print "Failed to set send mail";
            $dbCon->rollback();
            $dbCon->close();
            exit;
        }
    }
}

$itgResearchOptions = [];

try {
    $monitorSamplingService = new MonitorSamplingService();
    $database = new Database(kClientDBName, 'Asia/Tokyo');
    $database->connect();
    try {
        $monitorSamplingService->execute($dbCon, $cl_code, $re_id, $rehRow['reh_id']);
    } catch (Exception $e) {
        DB::connection()->disconnect();
        throw $e;
    }

    $itgResearchOption = $monitorSamplingService->retrieveIntageResearchOption($cl_code, $re_id, ['monitor_type']);

    DB::connection()->disconnect();

} catch (Exception $e) {
    $dbCon->rollback();
    $dbCon->close();
    log_error($e);
    exit;
}

output:

$dbCon->commit();

// 最新の配信情報を取得 add_saito 2011.02.25
$rehRowAry = researchutil_ReloadResearchHaishin($re_id, $dbCon);

$dbCon->close();

$re_setting_status = $reRow["re_setting_status"];

$intage_view_control_parameters = [];
if (ClientDetector::isIntage($cl_code)) {
    try {
        // クライアント設定読込
        $client_setting = new ClientSetting($cl_code);
        $client_setting->load();

        // DB接続
        $db = new Database($client_setting->get('kClientDBName'), 'Asia/Tokyo');
        $db->connect();

        $view_control_parameters = new ViewControlParameters();
        $intage_view_control_parameters = $view_control_parameters->collectByReId($re_id);

    } catch (\Exception $e) {
        log_error($e->getMessage().PHP_EOL.$e->getTraceAsString(), __FILE__);
        print "System Error.";
        exit;
    }
}

//混在配信であれば
if ($reRow['re_mix_haishin_flg'] == 1 && !is_null($rehRow['reh_type'])) {
    $templates = "admin/research/index_parts_mixhaishin.html";
} else {
    $templates = "admin/research/index_parts_haishin.html";
}
// *** テンプレート表示 *** //
$tpl = new CMSmarty("domain1");
$tpl->assign("rehRowAry", $rehRowAry);
$tpl->assign("LangBox", $LangBox);                    // add_nagaoka 2012.04.04
$tpl->assign("research_haishin_state", $research_haishin_state); //add murakami 2013.09.13 #7398
$tpl->assign("reRow", $reRow);
$tpl->assign("itgResearchOption", $itgResearchOption);
$tpl->assign('below_point_allow_flg', $below_point_allow_flg);
$tpl->assign('is_intage', ClientDetector::isIntage($cl_code));
$tpl->assign('intage_view_control_params', $intage_view_control_parameters);
$tpl->display($templates);
