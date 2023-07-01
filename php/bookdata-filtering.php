<?php
class GetBookData {
  private $book_data = [];
  private $isbn_title_list;

  public function __construct($isbn_title_list) {
    $this->isbn_title_list = $isbn_title_list;
    // 念の為1000件ずつに分けてリクエストを送る
    $isbn_list = array_chunk(array_keys($isbn_title_list), 1000);
    try {
      foreach ($isbn_list as $list) {
        $url = 'https://api.openbd.jp/v1/get?isbn=' . implode(',', $list);
        $json = file_get_contents($url);
        $this->book_data = array_merge($this->book_data, json_decode($json, true, 512, JSON_UNESCAPED_UNICODE));
      }
    } catch (Exception $e) {
      error_log($e->getMessage());
    }
  }

  public function filtering_book_data() {
    $filtering_book_data = [];
    foreach ($this->isbn_title_list as $isbn => $title) {
      $data = array_shift($this->book_data);
      if (is_null($data)) {
        $filtering_book_data[$isbn] = [
          'title' => $title,
          'author' => null,
          'publisher' => null,
          'short_introduction' => null,
          'long_introduction' => null,
          'pubdate' => null,
          'is_image' => false,
          'authors_list' => [],
        ];
      } else {
        $introduction_list = $this->get_introduction($data);
        $filtering_book_data[$isbn] = [
          'title' => $this->get_title($data),
          'title_kana' => $this->get_title_kana($data),
          'author' => $this->get_author($data),
          'publisher' => $this->get_publisher($data),
          'short_introduction' => $introduction_list[0],
          'long_introduction' => $introduction_list[1],
          'pubdate' => $this->get_pubdate($data),
          'keywords' => $this->get_keywords($data),
          'is_image' => $this->get_is_image($data),
          'authors_list' => $this->get_authors_list($data),
        ];
      }
    }
    return $filtering_book_data;
  }

  private function get_title($data) {
    if (!isset($data['summary']['title'])) return null;
    return $data['summary']['title'];
  }

  private function get_title_kana($data) {
    if (!isset($data['onix']['DescriptiveDetail']['TitleDetail']['TitleElement']['TitleText']['collationkey'])) return null;
    return $data['onix']['DescriptiveDetail']['TitleDetail']['TitleElement']['TitleText']['collationkey'];
  }

  private function get_author($data) {
    if (!isset($data['summary']['author'])) return null;
    return $data['summary']['author'];
  }

  private function get_publisher($data) {
    if (!isset($data['summary']['publisher'])) return null;
    return $data['summary']['publisher'];
  }

  private function get_introduction($data) {
    $shout_introduction = null;
    $long_introduction = null;
    if (!isset($data['onix']['CollateralDetail']['TextContent'])) return [null, null];
    foreach ($data['onix']['CollateralDetail']['TextContent'] as $intro) {
      if ($intro['ContentAudience'] === '00' && !empty($intro['Text'])) {
        if ($intro['TextType'] === '02') {
          $shout_introduction = $intro['Text'];
          $long_introduction = $long_introduction ?: $intro['Text'];
        }
        if ($intro['TextType'] === '03') {
          $long_introduction = $intro['Text'];
          $shout_introduction = $shout_introduction ?: $intro['Text'];
        }
      }
    }
    return [$shout_introduction, $long_introduction];
  }

  private function get_pubdate($data) {
    if (empty($data['summary']['pubdate'])) return null;
    $pubdate = $data['summary']['pubdate'];
    // 8桁,6桁,4桁で出力される可能性があるので、それぞれの場合分けをする
    if (preg_match('/^\d{8}|\d{4}-\d{2}-\d{2}$/', $pubdate)) {
      return date('Y年m月d日', strtotime($pubdate));
    } elseif (preg_match('/^\d{6}|\d{4}-\d{2}$/', $pubdate)) {
      return date('Y年m月', strtotime($pubdate));
    } elseif (preg_match('/^\d{4}$/', $pubdate)) {
      return date('Y年', strtotime($pubdate));
    }
  }

  private function get_keywords($data) {
    if (!isset($data['onix']['DescriptiveDetail']['Subject'])) return null;
    foreach ($data['onix']['DescriptiveDetail']['Subject'] as $subject) {
      if ($subject['SubjectSchemeIdentifier'] === '20') {
        return $subject['SubjectHeadingText'];
        break;
      }
    }
  }

  private function get_is_image($data) {
    return (isset($data['summary']['cover']) && !empty($data['summary']['cover']));
  }

  private function get_authors_list($data) {
    $authors_list = [];
    // book.onix.DescriptiveDetail.Contributor
    if (!isset($data['onix']['DescriptiveDetail']['Contributor'])) return null;
    foreach ($data['onix']['DescriptiveDetail']['Contributor'] as $contributor) {
      if (in_array('A01', $contributor['ContributorRole'])) {
        $authors_list[] = [
          'author' => $contributor['PersonName']['content'],
          'author_kana' => $contributor['PersonName']['collationkey'] ?? null,
          'author_description' => $contributor['BiographicalNote'] ?? null
        ];
      }
    }
    return $authors_list;
  }
}
