<?php
require_once '/vendor/autoload.php';
require_once __DIR__ . '/system-logger.php';

header("Content-Type: application/json; charset=utf-8");

function h($str) {
  return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

$logger = new Logger('search.php', '1.0', $_SERVER['REMOTE_ADDR']);
$json = array("status" => [
  "code" => 400,
  "message" => "Bad request",
  "count" => null,
  "page" => null,
]);

// POST通信以外はエラー
if ($_SERVER["REQUEST_METHOD"] != "POST") {
  $logger->write(3, '不正なリクエスト。(GET通信)', 'HTTP 401 Unauthorized');
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
if (!$dotenv->load()) {
  $logger->write(2, '環境変数の読み込みに失敗しました。', "Dotenv\Dotenv::load() failed");
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$campus = $_POST['campus'];
$campusList = json_decode(file_get_contents(dirname(__DIR__) . '/data/campus.json'), true);
if (empty($_POST['campus']) || !array_key_exists($campus, $campusList)) {
  $logger->write(3, '不正なリクエスト。(不明なキャンパス[' . $campus . '])', "HTTP 401 Unauthorized");
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}

$campus_library_table = $campusList[$campus]['table'];
$campus_tag_table = $campusList[$campus]['tags-table'];

// post情報を取得し、検索条件を設定
$order = '`id` ASC';
$page = (int)$_POST['page'] > 0 ? (int)$_POST['page'] : 1;
$limit = (($page - 1) * 20) . ', 20';
$search_word = h($_POST['search']);
$search_type = $_POST['search_type'];
if ($search_type != 'all' && empty($search_word)) {
  $search_type = NULL;
} else if ($search_type != 2) {
  $search_word = '%' . $search_word . '%';
}

$json['status']['page'] = $page;

try {
  $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
  $pdo = new PDO($_ENV['DSN'], $_ENV['USERNAME'], $_ENV['PASSWORD'], $options);

  switch ($search_type) {
    case 1: // title検索
      $WHERE = 'COALESCE(book.title, library.title) LIKE :search';
      break;
    case 2: // タグ検索
      $WHERE = 'tags.tag = :search';
      break;
    case 3: // キーワード検索
      $WHERE = '
      COALESCE(book.title, library.title) LIKE :search
        OR tags.tag LIKE :search OR author.author LIKE :search
        OR book.short_introduction LIKE :search
        OR book.long_introduction LIKE :search
        OR book.keywords LIKE :search
      ';
      break;
    case 'all': // 全体検索
      $WHERE = '1';
      break;
    default: // ランダム
      $WHERE = '1';
      $order = 'RAND()';
      $limit = '20';
      break;
  }

  // 検索結果の件数を取得
  $stmt = $pdo->prepare("
  SELECT
    COUNT(DISTINCT library.id)
  FROM
    `$campus_library_table` library
    LEFT JOIN `book-data` book ON library.isbn = book.isbn
    LEFT JOIN `author-data` author ON library.isbn = author.isbn
    LEFT JOIN `$campus_tag_table` tags ON library.id = tags.id
  WHERE $WHERE
  ");
  if (1 <= $search_type && $search_type <= 3) {
    $stmt->bindParam(':search', $search_word, PDO::PARAM_STR);
  }
  $stmt->execute();
  $count = $stmt->fetchColumn();
  $json['status']['count'] = $count;
  if ($count == 0) {
    $json['status']['code'] = 200;
    $json['status']['message'] = 'success';
    die(json_encode($json, JSON_UNESCAPED_UNICODE));
  }

  // 検索結果を取得
  $stmt = $pdo->prepare("
  SELECT
    library.id,
    book.isbn,
    COALESCE(book.title, library.title) AS title,
    book.author,
    book.publisher,
    book.short_introduction,
    book.long_introduction,
    book.pubdate,
    book.is_image,
    GROUP_CONCAT(author.author SEPARATOR '\t') AS authors,
    GROUP_CONCAT(IFNULL(author.author_kana, '') SEPARATOR '\t') AS authors_kana,
    GROUP_CONCAT(IFNULL(author.author_description, '') SEPARATOR '\t') AS authors_description,
    GROUP_CONCAT(DISTINCT tags.tag SEPARATOR '\t') AS tags
  FROM
    `$campus_library_table` library
    LEFT JOIN `book-data` book ON library.isbn = book.isbn
    LEFT JOIN `author-data` author ON library.isbn = author.isbn
    LEFT JOIN `$campus_tag_table` tags ON library.id = tags.id
  WHERE $WHERE
  GROUP BY
    library.id,
    book.isbn,
    book.title,
    book.author,
    book.publisher,
    book.short_introduction,
    book.long_introduction,
    book.pubdate,
    book.is_image
  ORDER BY $order
  LIMIT $limit
  ");
  if (1 <= $search_type && $search_type <= 3) {
    $stmt->bindParam(':search', $search_word, PDO::PARAM_STR);
  }
  $stmt->execute();
  $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($result as $row) {
    $json[$row['id']] = array(
      'isbn' => $row['isbn'],
      'title' => $row['title'],
      'author' => $row['author'],
      'publisher' => $row['publisher'],
      'short_introduction' => $row['short_introduction'],
      'long_introduction' => $row['long_introduction'],
      'pubdate' => $row['pubdate'],
      'is_image' => boolval($row['is_image']),
      'authors' => $row['author'] ? array_combine(
        explode("\t", $row['authors']),
        array_map(function($author_kana, $author_description) {
          return array('author_kana' => $author_kana, 'author_description' => $author_description);
        }, explode("\t", $row['authors_kana']), explode("\t", $row['authors_description']))
      ) : [],
      'tags' => $row['tags'] ? explode("\t", $row['tags']) : [],
    );
  }

  $json['status']['code'] = 200;
  $json['status']['message'] = 'success';
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
} catch (Exception $e) {
  $logger->write(2, 'データベース接続に失敗しました。', $e->getMessage());
  $json['status']['code'] = 500;
  $json['status']['message'] = "Internal Server Error";
  $json['status']['count'] = null;
  $json['status']['page'] = null;
  die(json_encode($json, JSON_UNESCAPED_UNICODE));
}
