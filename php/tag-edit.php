<?php
require_once '/vendor/autoload.php';
require_once __DIR__ . '/system-logger.php';

header("Content-Type: application/json; charset=utf-8");

function h($str) {
  return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

$logger = new Logger('tag-edit.php', '1.0', $_SERVER['REMOTE_ADDR']);
$json = array("status" => [
  "code" => 400,
  "message" => "Bad request",
]);

if ($_SERVER["REQUEST_METHOD"] != "POST" || !isset($_POST['id']) || !isset($_POST['tag'])) {
  $logger->write(3, '不正なリクエスト。(GET通信)', "HTTP 400 Bad Request");
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$campus = $_POST['campus'];
$campusList = json_decode(file_get_contents(dirname(__DIR__) . '/data/campus.json'), true);
if (empty($_POST['campus']) || !array_key_exists($campus, $campusList)) {
  $logger->write(3, '不正なリクエスト。(不明なキャンパス[' . $campus . '])', "HTTP 401 Unauthorized");
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
if (!$dotenv->load()) {
  $logger->write(2, '環境変数の読み込みに失敗しました。', "Dotenv\Dotenv::load() failed");
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$campus_tag_table = $campusList[$campus]['tags-table'];
$id = $_POST['id'];
$tag = $_POST['tag'];
$type = $_POST['type'];

// tags.jsonの読み込み
$tags = json_decode(file_get_contents(dirname(__DIR__) . "/data/tags.json"), true);

// idがintでなければエラー
if (!is_numeric($id) || !in_array($tag, $tags)) {
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

try {
  $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
  $pdo = new PDO($_ENV['DSN'], $_ENV['USERNAME'], $_ENV['PASSWORD'], $options);
  $stmt = $pdo->prepare("SELECT * FROM `$campus_tag_table` WHERE id = :id AND tag = :tag");
  $stmt->bindParam(':id', $id, PDO::PARAM_INT);
  $stmt->bindParam(':tag', $tag, PDO::PARAM_STR);
  $stmt->execute();
  $count = $stmt->fetchColumn();
  if ($type === "add" && $count == 0) {
    $sql = "INSERT INTO `$campus_tag_table` (id, tag) VALUES (:id, :tag)";
  } else if ($type === "remove" && $count > 0) {
    $sql = "DELETE FROM `$campus_tag_table` WHERE id = :id AND tag = :tag";
  } else {
    $json["status"]["code"] = 204;
    $json["status"]["message"] = "No Content";
    die(json_encode($json, JSON_UNESCAPED_UNICODE));
  }
  $stmt = $pdo->prepare($sql);
  $stmt->bindParam(':id', $id, PDO::PARAM_INT);
  $stmt->bindParam(':tag', $tag, PDO::PARAM_STR);
  $stmt->execute();
  $json["status"]["code"] = 201;
  $json["status"]["message"] = "Created";
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
} catch (Exception $e) {
  $json["status"]["code"] = 500;
  $json["status"]["message"] = "Internal Server Error";
  $logger->write(2, 'データベースの接続に失敗しました。', $tag . ': ' . $e->getMessage());
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}
