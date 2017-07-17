<?php

// Composerでインストールしたライブラリを一括読み込み
require_once __DIR__ . '/vendor/autoload.php';
// テーブル名を定義
define('TABLE_NAME_SHEETS' , 'sheets');

// アクセストークンを使いCurlHTTPClientをインスタンス化
$httpClient = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));

// CurlHTTPClientとシークレットを使いLINEBotをインスタンス化
$bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);

// LINE Messaging APIがリクエストに付与した署名を取得
$signature = $_SERVER['HTTP_' . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

// 署名が妥当かチェック。妥当であればリクエストをパースし配列へ
// 不正であれば例外の内容を出力
try {
  $events = $bot->parseEventRequest(file_get_contents('php://input'), $signature);
} catch(\LINE\LINEBot\Exception\InvalidSignatureException $e) {
  error_log('parseEventRequest failed. InvalidSignatureException => '.var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownEventTypeException $e) {
  error_log('parseEventRequest failed. UnknownEventTypeException => '.var_export($e, true));
} catch(\LINE\LINEBot\Exception\UnknownMessageTypeException $e) {
  error_log('parseEventRequest failed. UnknownMessageTypeException => '.var_export($e, true));
} catch(\LINE\LINEbot\Exception\InvalidEventRequestException $e) {
  error_log('parseEventRequest failed. InvalidEventRequestException => '.var_export($e, true));
}

// 配列に格納された各イベントをループ処理
foreach ($events as $event) {
  // MessageEventクラスのインスタンスでなければ処理をスキップ
  if (!($event instanceof \LINE\LINEBot\Event\MessageEvent)) {
    error_log('Non message event has come');
    continue;
  }
  // TextMessageクラスのインスタンスでなければ処理をスキップ
  if(!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage)) {
    error_log('Non text message has come');
    continue;
  }

  // リッチコンテンツがタップされた時
  if(substr($event->getText(), 0, 4) == 'cmd_') {
    // ルーム作成
    if(substr($event->getText(), 4) == 'newroom') {
      // ユーザーが未入室の場合
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        // ルームを作成し入室後ルームIDを取得
        $roomId = createRoomAndGetRoomId($event->getUserId());
        // ルームIDをユーザーに返信
        replyMultiMessage($bot, $event->getReplyToken(),
          new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('ルームを作成し、入室しました。ルームIDは'),
          new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($roomId),
          new \LINE\LINEBot\MessageBuilder\TextMessageBuilder('です。'));
      // すでに入室しているとき
      } else {
        replyTextMessage($bot, $event->getReplyToken(), 'すでに入室済みです。');
      }
    }
    // 入室
    else if(substr($event->getText(), 4) == 'enter') {
      // ユーザーが未入室の時
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームIDを入力してください。');
      } else {
        replyTextMessage($bot, $event->getReplyToken(), '入室済みです。');
      }
    }
    // 退室の確認ダイアログ
    else if(substr($event->getText(), 4) == 'leave_confirm') {
      replyConfirmTemplate($bot, $event->getReplyToken(), '本当に退出しますか？', '本当に退出しますか？',
        new LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder('はい', 'cmd_leave'),
        new LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder('いいえ', 'cancecl'));
    }
    // 退室
    else if(substr($event->getText(), 4) == 'leave') {
      if(getRoomIdOfUser($event->getUserId()) !== PDO::PARAM_NULL) {
        leaveRoom($event->getUserId());
        replyTextMessage($bot, $event->getReplyToken(), '退室しました。');
      } else {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っていません。');
      }
    }
    // ルームでのビンゴをスタート
    else if(substr($event->getText(), 4) == 'start') {
      if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'ルームに入っていません。');
      } else if(getSheetOfUser($event->getUserId()) !== PDO::PARAM_NULL) {
        replyTextMessage($bot, $event->getReplyToken(), 'すでに配布されています。');
      } else {
        // シートを準備
        prepareSheets($bot, $event->getUserId());
      }
    }
    continue;
  }
  // リッチコンテンツ以外の時(ルームIDが入力されたとき)
  if(getRoomIdOfUser($event->getUserId()) === PDO::PARAM_NULL) {
    // 入室
    $roomId = enterRoomAndGetRoomId($event->getUserId(), $event->getText());
    // 成功時
    if($roomId !== PDO::PARAM_NULL) {
      replyTextMessage($bot, $event->getReplyToken(), "ルームID" . $roomId . "に入室しました。");
    } else {
      replyTextMessage($bot, $event->getReplyToken(), "そのルームIDは存在しません");
    }
  }

}

// ユーザーIDからルームIDを取得
function getRoomIdOfUser($usrId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select roomid from ' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['roomid'];
  }
}

// ルームを作成し入室後ルームIDを返す
function createRoomAndGetRoomId($userId) {
  $roomId = uniqid();
  $dbh = dbConnection::getConnection();
  $sql = 'insert into ' . TABLE_NAME_SHEETS . ' (userid, sheet, roomid) values (pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'), ?, ?) ';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId, PDO::PARAM_NULL, $roomId));
  return $roomId;
}

// 入室しルームIDを返す
function enterRoomAndGetRoomId($userId, $roomId) {
  $dbh = dbConnection::getConnection();
  $sql = 'insert into ' . TABLE_NAME_SHEETS . ' (userid, sheet, roomid) SELECT pgp_sym_encrypt(?, \'' . getenv('DB_ENCRYPT_PASS') . '\'), ?, ? where exists(
    select roomid from ' . TABLE_NAME_SHEETS . ' where roomid = ?) returning roomid';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId, PDO::PARAM_NULL, $roomId, $roomId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return $row['roomid'];
  }
}
// 退室
function leaveRoom($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'delete FROM' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
}

// ユーザーIDからシートを取得
function getSheetOfUser($userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select sheet from ' . TABLE_NAME_SHEETS . ' where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array($userId));
  if (!($row = $sth->fetch())) {
    return PDO::PARAM_NULL;
  } else {
    return json_decode($row["sheet"]);
  }
}

// 各ユーザーにシートを割り当て
function prepareSheets($bot, $userId) {
  $dbh = dbConnection::getConnection();
  $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  $sth->execute(array(getRoomIdOfUser($userId)));
  foreach ($sth->fetchAll() as $row) {
    $sheetArray = array();
    for($i = 0; $i < 5; $i++) {
      // 各列範囲内でランダム
      $numArray = range(($i * 15) + 1, ($i * 15) + 1 + 14);
      // シャッフル
      shuffle($numArray);
      // 5番目までの要素を追加
      array_push($sheetArray, array_slice($numArray, 0, 5));
    }
    // 中央マスは0
    $sheetArray[2][2] = 0;
    // アップデート
    updateUserSheet($row['userid'], $sheetArray);
  }
  // すべてのユーザーにシートのImagemapを送信
  pushSeetToUser($bot, $userId, 'ビンゴ開始！');
}

// ユーザーのシートをアップデート
function updateUserSheet($userId, $sheet) {
  $dbh = dbConnection::getConnection();
  $sql = 'update ' . TABLE_NAME_SHEETS . ' set sheet = ? where ? = pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\')';
  $sth = $dbh->prepare($sql);
  $sth->execute(array(json_encode($sheet), $userId));
}

// すべてのユーザーにシートのImagemapを送信
function pushSeetToUser($bot, $userId, $text) {
  $dbh = dbConnection::getConnection();
  $sql = 'select pgp_sym_decrypt(userid, \'' . getenv('DB_ENCRYPT_PASS') . '\') as userid, sheet from ' . TABLE_NAME_SHEETS . ' where roomid = ?';
  $sth = $dbh->prepare($sql);
  array_push($actionArray, new LINE\LINEBot\ImagemapActionBuilder\ImagemapMessageActionBuilder('-', new LINE\LINEBot\ImagemapActionBuilder\AreaBuilder(0, 0, 1, 1)));

  // ユーザー一人ずつ処理
  foreach ($sth->fetchAll() as $row) {
    $imagemapMessageBuilder = new LINE\LINEBot\MessageBuilder\imagemapMessageBuilder('https://' . $_SERVER['HTTP_HOST'] . '/sheet/' . urlencode($row['sheet']) . '/' . urlencode(json_encode([0])) . '/' . uniqid(), 'シート', new LINE\LINEBot\MessageBuilder\Imagemap\BaseSizeBuilder(1040, 1040), $actionArray);
    $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
    $builder->add(new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));
    $builder->add($imagemapMessageBuilder);
    $bot->pushMessage($row['userid'], $builder);
  }
}

// テキストを返信。引数はLINEBot、返信先、テキスト
function replyTextMessage($bot, $replyToken, $text) {
  // 返信を行いレスポンスを取得
  // TextMessageBuilderの引数はテキスト
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($text));
  // レスポンスが異常な場合
  if (!$response->isSucceeded()) {
    // エラー内容を出力
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 画像を返信。引数はLINEBot、返信先、画像URL、サムネイルURL
function replyImageMessage($bot, $replyToken, $originalImageUrl, $previewImageUrl) {
  // ImageMessageBuilderの引数は画像URL、サムネイルURL
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\ImageMessageBuilder($originalImageUrl, $previewImageUrl));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 位置情報を返信。引数はLINEBot、返信先、タイトル、住所、
// 緯度、経度
function replyLocationMessage($bot, $replyToken, $title, $address, $lat, $lon) {
  // LocationMessageBuilderの引数はダイアログのタイトル、住所、緯度、経度
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder($title, $address, $lat, $lon));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// スタンプを返信。引数はLINEBot、返信先、
// スタンプのパッケージID、スタンプID
function replyStickerMessage($bot, $replyToken, $packageId, $stickerId) {
  // StickerMessageBuilderの引数はスタンプのパッケージID、スタンプID
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder($packageId, $stickerId));
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 動画を返信。引数はLINEBot、返信先、動画URL、サムネイルURL
function replyVideoMessage($bot, $replyToken, $originalContentUrl, $previewImageUrl) {
  // VideoMessageBuilderの引数は動画URL、サムネイルURL
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\VideoMessageBuilder($originalContentUrl, $previewImageUrl));
  if (!$response->isSucceeded()) {
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// オーディオファイルを返信。引数はLINEBot、返信先、
// ファイルのURL、ファイルの再生時間
function replyAudioMessage($bot, $replyToken, $originalContentUrl, $audioLength) {
  // AudioMessageBuilderの引数はファイルのURL、ファイルの再生時間
  $response = $bot->replyMessage($replyToken, new \LINE\LINEBot\MessageBuilder\AudioMessageBuilder($originalContentUrl, $audioLength));
  if (!$response->isSucceeded()) {
    error_log('Failed! '. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// 複数のメッセージをまとめて返信。引数はLINEBot、
// 返信先、メッセージ(可変長引数)
function replyMultiMessage($bot, $replyToken, ...$msgs) {
  // MultiMessageBuilderをインスタンス化
  $builder = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
  // ビルダーにメッセージを全て追加
  foreach($msgs as $value) {
    $builder->add($value);
  }
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Buttonsテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// 画像URL、タイトル、本文、アクション(可変長引数)
function replyButtonsTemplate($bot, $replyToken, $alternativeText, $imageUrl, $title, $text, ...$actions) {
  // アクションを格納する配列
  $actionArray = array();
  // アクションを全て追加
  foreach($actions as $value) {
    array_push($actionArray, $value);
  }
  // TemplateMessageBuilderの引数は代替テキスト、ButtonTemplateBuilder
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
    $alternativeText,
    // ButtonTemplateBuilderの引数はタイトル、本文、
    // 画像URL、アクションの配列
    new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder ($title, $text, $imageUrl, $actionArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Confirmテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// 本文、アクション(可変長引数)
function replyConfirmTemplate($bot, $replyToken, $alternativeText, $text, ...$actions) {
  $actionArray = array();
  foreach($actions as $value) {
    array_push($actionArray, $value);
  }
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
    $alternativeText,
    // Confirmテンプレートの引数はテキスト、アクションの配列
    new \LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder ($text, $actionArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// Carouselテンプレートを返信。引数はLINEBot、返信先、代替テキスト、
// ダイアログの配列
function replyCarouselTemplate($bot, $replyToken, $alternativeText, $columnArray) {
  $builder = new \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder(
  $alternativeText,
  // Carouselテンプレートの引数はダイアログの配列
  new \LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder (
   $columnArray)
  );
  $response = $bot->replyMessage($replyToken, $builder);
  if (!$response->isSucceeded()) {
    error_log('Failed!'. $response->getHTTPStatus . ' ' . $response->getRawBody());
  }
}

// データベースへの接続を管理するクラス
class dbConnection {
  // インスタンス
  protected static $db;
  // コンストラクタ
  private function __construct() {

    try {
      // 環境変数からデータベースへの接続情報を取得し
      $url = parse_url(getenv('DATABASE_URL'));
      // データソース
      $dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
      // 接続を確立
      self::$db = new PDO($dsn, $url['user'], $url['pass']);
      // エラー時例外を投げるように設定
      self::$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    }
    catch (PDOExcepton $e) {
      echo 'Connection Error: ' . $e->getMessage();
    }
  }

  // シングルトン、存在しない場合のみインスタンス化
  public static function getConnection(){
    if (!self::$db) {
      new dbConnection();
    }
    return self::$db;
  }
}

 ?>
