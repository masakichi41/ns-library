<?php
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
  http_response_code(400);
  exit;
}

$isbn = $_GET['isbn'] ?? null;

if (!preg_match('/^\d{10,13}$/', $isbn)) {
  http_response_code(400);
  exit;
}

// cover.openbd.jpのAPIを叩いて、カバー画像のURLを取得し../data/book-cover/に保存する
$cover_url = 'https://cover.openbd.jp/' . $isbn . '.jpg';
$cover_path = dirname(__DIR__) . '/data/book-cover/' . $isbn . '.jpg';
$cover = file_get_contents($cover_url);
if ($cover) {
  file_put_contents($cover_path, $cover);
}
