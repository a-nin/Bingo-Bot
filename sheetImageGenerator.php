<?php

// Composerでインストールしたライブラリを一括読み込み
require_once __DIR__ . '/vendor/autoload.php';
// 合成のベースとなるサイズを定義
define('GD_BASE_SIZE', 700);

// シートの情報を受け取り配列に変換
$sheet = json_decode(urldecode($_REQUEST['sheet']));
// 引かれたボールの情報を受け取り配列に変換
$balls = json_decode(urldecode($_REQUEST['balls']));

// 数字が合成済みの画像の名前
$sheetName = json_encode($sheet) . '.png';
// 保存されていれば
if(file_exsists('./tmp/' . $sheetName)) {
  // 保存された画像を合成のベースに変更
  $destinationImage = imagecreatefrompng('./tmp/' . $sheetName);
  // 数字とボールの配列を比較し穴を合成
  for($i = 0; $i < count($sheet); $i++) {
    $col = $sheet[$i];
    for($j = 0; $j < count($col); $j++) {
      if(in_array($col[$j], $balls)) {
        $holeImage = imagecreatefrompng('imgs/hole.png');
        imagecopy($destinationImage, $holeImage, 15 + (int)($i * 134), 116 + (int)($j * 114), 0, 0, 134, 114);
        imagedestroy($holeImage);
      }
    }
  }
// 保存されていなければ
} else {
  // 空のシート画像を生成
  $destinationImage = imagecreatefrompng('imgs/bingo_bg.png');
  for($i = 0; $i < count($col); $i++) {
    $col = $sheet[$i];
    for($j = 0; $j < count($col); $j++) {
      // 数字を合成。中央は何もしない
      if($col[$j] != 0) {
        // 数字の画像を取得
        $numImage = imagecreatefrompng('imgs/' . str_pad($col[$j], 2, 0, STR_PAD_LEFT) . '.png');
        imagecopy($destinationImage, $numImage, 15 + (int)($i * 134), 116 + (int)($j * 114), 0, 0, 134, 114);
        imagedestroy($numImage);
      }
      // 数字とボールの配列を比較し穴を合成
      if(in_array($col[$j], $balls)) {
        $holeImage - imagecreatefrompng('imgs/hole.png');
        imagecopy($destinationImage, $holeImage, 15 + (int)($i * 134), 116 + (int)($j * 114), 0, 0, 134, 114);
        imagedestroy($holeImage);
      }
    }
  }
  // 画像の保存先フォルダを定義
  $directory_path = './tmp/';
  // フォルダが存在しない時
  if(!file_exsists($directory_path)) {
    // フォルダを作成
    if(mkdir($directory_path, 0777, true)) {
      // 権限を変更
      chmod($directory_path, 0777);
    }
  }
  // 現在の画像をフォルダに保存
  imagepng($destinationImage, $directory_path . $sheetName, 9);
}

// リクエストされているサイズを取得
$size = $_REQUEST['size'];
// ベースサイズと同じなら何もしない
if($size == GD_BASE_SIZE) {
  $out = $destinationImage;
// 違うサイズの場合
} else {
  // リクエストされたサイズの空の画像を生成
  $out = imagecreatetruecolor($size, $size);
  // リサイズしながら合成
  imagecopyresampled($out, $destinationImage, 0, 0, 0, 0, $size, $size, GD_BASE_SIZE, GD_BASE_SIZE);
}

// 出力のバッファリングを有効に
ob_start();
// バッファに出力
imagepng($out, null, 9);
// バッファから画像を取得
$content = ob_get_contents();
// バッファを消去し出力のバッファリングをオフ
ob_end_clean();

// 出力のタイプを指定
header('Content-type: image/png');
// 画像を出力
echo $content;

 ?>
