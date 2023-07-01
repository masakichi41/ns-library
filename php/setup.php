<?php
require_once '/vendor/autoload.php';
require_once __DIR__ . '/system-logger.php';
require_once __DIR__ . '/bookdata-filtering.php';

// エスケープ処理
function h($s) {
  if (empty($s)) return null;
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

// データが空の時にデータベースにnullを入れるための処理
function n($s, $t) {
  if (empty($s)) return [null, PDO::PARAM_NULL];
  return [h($s), $t];
}

$logger = new Logger('setup.php', '1.0', $_SERVER['REMOTE_ADDR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['campus']) || !isset($_POST['token']) || !isset($_POST['data'])) {
  $logger->write(3, '不正なリクエスト。(GET通信, POSTの値不足)', 'HTTP 401 Unauthorized', 401);
}

// 存在するキャンパスかどうかを確認する
$campus = $_POST['campus'];
$campusList = json_decode(file_get_contents(dirname(__DIR__) . '/data/campus.json'), true);
if (empty($campus) || !array_key_exists($campus, $campusList)) {
  $logger->write(3, "不正なリクエスト。(不明なキャンパス[$campus])", 'HTTP 401 Unauthorized', 401);
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
if (!$dotenv->load()) {
  $logger->write(2, '環境変数の読み込みに失敗しました。', "Dotenv\Dotenv::load() failed", 500);
}

// jsonデータは{id: {isbn: '[10桁or13桁]', title: '[text]'}...}の形式で送られてくる
$bookdata = json_decode($_POST['data'], true);
if (empty($bookdata)) {
  $logger->write(2, 'JSONデータの読み込みに失敗しました。', 'invalid json_data: ' . $_POST['data'], 500);
}
// openBDへのリクエスト時に使うコピー
$all_bookdata = $bookdata;
$log = "campus: $campus";

try {
  // campus.jsonに保管されているテーブル名を取得する
  $campus_table = $campusList[$campus]['table'];
  $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
  $pdo = new PDO($_ENV['DSN'], $_ENV['USERNAME'], $_ENV['PASSWORD'], $options);

  // トークンの確認
  $stmt = $pdo->prepare("SELECT `token` FROM `campus-auth` WHERE `campus` = :campus");
  $stmt->bindValue(':campus', $campus, PDO::PARAM_STR);
  $stmt->execute();
  $token = $stmt->fetchColumn();
  if (!password_verify($_POST['token'], $token)) {
    $logger->write(3, '不正なリクエスト。(不正なトークン)', 'HTTP 401 Unauthorized', 401);
  }

  // 最終更新日時を記録する
  $stmt = $pdo->prepare("UPDATE `campus-auth` SET `time-stamp` = CURRENT_TIMESTAMP WHERE `campus` = :campus");
  $stmt->bindValue(':campus', $campus, PDO::PARAM_STR);
  $stmt->execute();

  // データベース側のデータを取得する
  $stmt = $pdo->prepare("SELECT `id`, `isbn` FROM `$campus_table` ORDER BY id ASC");
  $stmt->execute();
  $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // データベース側のデータと比較する
  // 更新が必要なデータを以下の配列に格納する
  $update_data_list = [];
  $update_sql_list = ['title' => [], 'isbn' => []];
  foreach ($result as $row) {
    $id = $row['id'];
    $data = $bookdata[$id];
    if (empty($data)) continue;
    // DBに存在するデータは$bookdataから削除し残ったものを新規データとして扱う
    unset($bookdata[$id]);
    // 更新用のデータとSQLを作成する
    if (!is_null($data['isbn']) && $data['isbn'] !== $row['isbn']) {
      $update_data_list[$id] = $data;
      $update_sql_list['id'][] = ":id_$id";
      $update_sql_list['title'][] = "WHEN :id_$id THEN :title_$id";
      $update_sql_list['isbn'][] = "WHEN :id_$id THEN :isbn_$id";
    }
  }
  if (!empty($update_data_list)) {
    $log .= ' UPDATE: ' . count($update_data_list);
    $id_sql = implode(', ', $update_sql_list['id']);
    $title_sql = implode(' ', $update_sql_list['title']);
    $isbn_sql = implode(' ', $update_sql_list['isbn']);
    $stmt = $pdo->prepare("UPDATE `$campus_table` SET `title` = CASE `id` $title_sql END, `isbn` = CASE `id` $isbn_sql END WHERE `id` IN ($id_sql)");
    foreach ($update_data_list as $id => $data) {
      $stmt->bindValue(":id_$id", $id, PDO::PARAM_INT);
      $stmt->bindValue(":title_$id", h($data['title']), PDO::PARAM_STR);
      $stmt->bindValue(":isbn_$id", h($data['isbn']), PDO::PARAM_STR);
    }
    $stmt->execute();
  }

  // 新規データをデータベースに追加する
  if (!empty($bookdata)) {
    $log .= ' INSERT: ' . count($bookdata);
    $insert_sql = implode(', ', array_map(function($id) {
      return "(:id_$id, :title_$id, :isbn_$id)";
    }, array_keys($bookdata)));
    $stmt = $pdo->prepare("INSERT INTO `$campus_table` (`id`, `title`, `isbn`) VALUES $insert_sql");
    foreach ($bookdata as $id => $data) {
      $stmt->bindValue(":id_$id", $id, PDO::PARAM_INT);
      $stmt->bindValue(":title_$id", h($data['title']), PDO::PARAM_STR);
      $stmt->bindValue(":isbn_$id", h($data['isbn']), PDO::PARAM_STR);
    }
    $stmt->execute();
  }

  // book-dataに存在しないISBNと本のデータをbook-dataに追加する
  $isbn_list = $pdo->query("SELECT `isbn` FROM `book-data`")->fetchAll(PDO::FETCH_COLUMN);
  // $all_bookdataからisbnをキー、titleを値とする連想配列を作成する(重複,null削除)
  $request_isbn_list = [];
  foreach ($all_bookdata as $id => $data) {
    if (!is_null($data['isbn']) && !in_array($data['isbn'], $isbn_list)) {
      $request_isbn_list[$data['isbn']] = $data['title'];
    }
  }

  if (!empty($request_isbn_list)) {
    $log =' INSERT book-data: ' . count($request_isbn_list);
    $getBookData = new GetBookData($request_isbn_list);

    // 保存するデータの取得・格納
    $filtering_book_data = $getBookData->filtering_book_data();

    $insert_sql = implode(', ', array_map(function($isbn) {
      return "(:isbn_$isbn, :title_$isbn, :title_kana_$isbn, :author_$isbn, :publisher_$isbn, :short_introduction_$isbn, :long_introduction_$isbn, :pubdate_$isbn, :keywords_$isbn, :is_image_$isbn, CURRENT_TIMESTAMP)";
    }, array_keys($filtering_book_data)));

    $stmt = $pdo->prepare("INSERT INTO `book-data` (`isbn`, `title`, `title_kana`, `author`, `publisher`, `short_introduction`, `long_introduction`, `pubdate`, `keywords`, `is_image`, `time-stamp`) VALUES $insert_sql");
    foreach ($filtering_book_data as $isbn => $data) {
      $stmt->bindValue(":isbn_$isbn", $isbn, PDO::PARAM_STR);
      $stmt->bindValue(":title_$isbn", h($data['title']), PDO::PARAM_STR);
      $stmt->bindValue(":title_kana_$isbn", h($data['title_kana']), PDO::PARAM_STR);
      $stmt->bindValue(":author_$isbn", h($data['author']), PDO::PARAM_STR);
      $stmt->bindValue(":publisher_$isbn", h($data['publisher']), PDO::PARAM_STR);
      $stmt->bindValue(":short_introduction_$isbn", h($data['short_introduction']), PDO::PARAM_STR);
      $stmt->bindValue(":long_introduction_$isbn", h($data['long_introduction']), PDO::PARAM_STR);
      $stmt->bindValue(":pubdate_$isbn", h($data['pubdate']), PDO::PARAM_STR);
      $stmt->bindValue(":keywords_$isbn", h($data['keywords']), PDO::PARAM_STR);
      $stmt->bindValue(":is_image_$isbn", $data['is_image'], PDO::PARAM_BOOL);
    }

    $stmt->execute();
    // 著者は複数人いることがあるので、isbnと著者を主キーにして別途保存
    $author_insert_sql = implode(', ', array_reduce(array_keys($filtering_book_data), function($carry, $isbn) use ($filtering_book_data) {
      $authors = array_map(function($index) use ($isbn) {
        $code = $isbn . '_' . $index;
        return "(:isbn_$code, :author_$code, :author_kana_$code, :author_description_$code)";
      }, array_keys($filtering_book_data[$isbn]['authors_list']));
      if (count($authors) > 0) return array_merge($carry, $authors);
      return $carry;
    }, []));

    $log .= ' INSERT author-data: ' . count($filtering_book_data);
    $stmt = $pdo->prepare("INSERT INTO `author-data` (`isbn`, `author`, `author_kana`, `author_description`) VALUES $author_insert_sql");
    foreach ($filtering_book_data as $isbn => $data) {
      foreach ($data['authors_list'] as $index => $author) {
        $code = $isbn . '_' . $index;
        $stmt->bindValue(":isbn_$code", $isbn, PDO::PARAM_STR);
        $stmt->bindValue(":author_$code", $author['author'], PDO::PARAM_STR);
        $stmt->bindValue(":author_kana_$code", h($author['author_kana']), PDO::PARAM_STR);
        $stmt->bindValue(":author_description_$code", h($author['author_description']), PDO::PARAM_STR);
      }
    }
    $stmt->execute();
  }
  $logger->write(0, 'データベースの更新が完了しました。', $log, 200);
} catch (Exception $e) {
  $logger->write(2, "データベースに接続できませんでした。 $log", $e->getMessage(), 500);
} catch (Throwable $e) {
  $logger->write(2, "データベースに接続できませんでした。 $log", $e->getMessage(), 500);
}
