# N/S高図書システム(仮称)
## 概要

N/S高の各キャンパスの本を見れるようなWebアプリケーション

- 動作環境
    - **PHP** 8.2<br>
    - ロリポップ スタンダード(現在廃止)

- アプリケーションの機能概要 ※DB構造は後述
    -  本のデータが保存されたスプレッドシートからデータを取得し、データベース`<campus名>-library-data`に保存する。
    - isbnを使い、openBDから取得した情報を`book-data`保存する。
    - 著者情報は`author-data`に保存する。
    - タグは各キャンパスのページから編集できる。`php/tag-edit.php`から`<campus名>-tags-data`にデータを保存する
    - アクセス時に`<campus名>-library-data`からISBN, `book-data`からデータ, `<campus名>-tags-data`からタグを取得して、SQLで検索を行い取得したデータを返す
    - タグの編集は`php/tag-edit.php`から行う
    - 表紙画像は`data/book-cover`に`{isbn}.jpg`で保存されている。ない場合は`get-cover.php`からopenBDの画像を取得し保存する。
    - エラーログは`php/log/system-log.csv`に保存される

## ディレクトリ構成
※パーミッション未記入は ファイル: 644 ディレクトリ: 755
```
.
├── css
│   ├── footer.css
│   ├── header.css
│   ├── modal.css
│   └── style.css
├── data
│   ├── book-cover
│   │   └── <isbn>.jpg
│   ├── 600 campus.json
│   └── 600 tags.json
├── img
│   ├── arrow.svg
│   ├── book.svg
│   ├── close.svg
│   ├── error.png
│   ├── loading.gif
│   ├── no-image.png
│   ├── remove.svg
│   └── search.svg
├── js
│   ├── jquery-3.7.0.min.js
│   ├── script.js
│   └── script.min.js
├── php
│   ├── 700 log
│   │   └── 600 system-log.csv
│   ├── 600 .env
│   ├── bookdata-filtering.php
│   ├── get-cover.php
│   ├── search.php
│   ├── setup.php
│   ├── system-logger.php
│   └── tag-edit.php
├── faq.html
├── site-terms-of-use.html
└── template.html
```

```
googleAppScript
└── NSL-GAS.gs
```

## データベース構造

**<campus名>-library-data** (主キー: id)

```
id: int(11)
title: text
isbn: varchar(13)
```

**<campus名>-tags-data**

```
id: int(11)
tag: text
```

**book-data** (主キー: isbn)

```
isbn: varchar(13)
title: text
title_kana: text
author: text
publisher: text
shout_introduction: text
long_introduction: text
pubdate: varchar(10)
keywords: text
is_image: tinyint(1)
time-stamp: timestamps
```

**author-data** (主キー: isbn, author)

```
isbn: varchar(13)
author: varchar(64)
author_kana: text
author_description: text
```

**campus-auth** (主キー: campus)

```
campus: varchar(16)
token: char(60)
time-stamp: timestamps
```
