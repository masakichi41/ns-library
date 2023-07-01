"use strict";
$(function() {
  // デバッグ用 クエリパラメータにdebugがあるときのみ実行
  const debugConsole = (s, c=null) => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has("debug")) return;
    if (c == null) console.log(s);
    else console.log("%c" + s, "color: " + c);
  }

  // htmlアンエスケープ
  const unescape = s => {
    if (s == null || s === "") return "";
    return s.replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&#x60/g, "`")
  }

  // 検索処理
  const lookup = (s_word=null, s_type=null) => {
    let search = s_word ?? $("#search_bar").val().slice(0, 255).trim();
    if (search == null || search == "") return true;
    const type = s_type ?? $(".type-btn.active").data("type");
    const url = new URL(window.location.href);
    url.searchParams.set("search", search);
    url.searchParams.set("type", type);
    url.searchParams.set("page", 1);
    location.href = url.href;
    return false;
  }

  // モーダルを閉じる処理
  const close = () => {
    $("#modal").remove();
    $("#modal_container").removeClass("active");
    // スクロール位置が記録されている場合は、その位置までスクロールしてからbodyのoverflowをvisibleにする
    if (scrollpos !== null) {
      $('body,html').animate({scrollTop: scrollpos}, 250);
      setTimeout(function() {
        $("body").css("overflow", "visible");
      }, 250);
    } else { $("body").css("overflow", "visible") }
    scrollpos = null;
    modal_id = null;
  }

  const log = (title, content, type=0) => {
    console.log(title, content, type);
    console.log(type);

    const color = ["#d1f2a5", "#FFFFb0", "#FFC0CB"][type];
    console.log(color);
    const log = $("<div>", { class: "log" })
      .css("background-color", color)
      .append(
        $("<div>", { class: "log-title bold" }).text(title),
        $("<div>", { class: "log-content" }).text(content),
        $("<span>", { class: "log-close" })
      );
    $("#log_container").append(log);
    // 5秒後にログを徐々にフェードアウトさせ消す
    setTimeout(function () {
      log.fadeOut(1000, () => { log.remove(); });
    }, 2500);
  };

  let modal_id = null;
  // 検索情報の取得
  const url = new URL(window.location.href);
  const campus = url.pathname.split('/').pop().split('.')[0];
  const search_opt = {
    campus: campus,
    search: url.searchParams.get("search"),
    search_type: url.searchParams.get("type"),
    page: Number(url.searchParams.get("page")) ?? 1
  };
  if (url.searchParams.has("search")) {
    if (search_opt.search_type < 1 || search_opt.search_type > 3) {
      url.searchParams.set("type", "3");
      location.href = url.href;
    }
    search_opt.search = search_opt.search.slice(0, 255);
    search_opt.search_type = Number(search_opt.search_type);
  } else if (url.searchParams.get("type") !== "all" && (url.searchParams.has("type") || url.searchParams.has("page"))){
    // エラー対策でパラメータを全て消去する
    url.searchParams.delete("type");
    url.searchParams.delete("page");
    location.href = url.href;
  }

  $("#search_bar").val(search_opt.search);
  debugConsole(search_opt);
  $.post("./php/search.php", search_opt, function(data) {
    const status = data.status;
    delete data.status;
    book_data = data;
    debugConsole(status);

    // 検索情報の表示
    const page_cnt = (status.page-1)*20+1 ?? 1;
    const start_num = page_cnt > status.count ? "XX" : page_cnt;
    const end_num =  page_cnt > status.count ? "XX" : Math.min(status.page*20, status.count);
    if (status.count == 0) {
      $("<div>", {id: "search_result"}).text("検索結果が見つかりませんでした").append(
        $("<div>", {id: "page_detail"}).text(`検索ワードを変更して再度検索してください`)
      ).appendTo("#result_background_img");
    } else if ("all" == search_opt.search_type) {
      $("<div>", {id: "search_result"}).text("全ての図書(" + status.count + "件)").append(
        $("<div>", {id: "page_detail"}).text(`全体検索 ${start_num}〜${end_num}件目を表示中`)
      ).appendTo("#result_background_img");
    } else if (1 <= search_opt.search_type && search_opt.search_type <= 3) {
      const type = ["タイトル", "タグ", "キーワード"][search_opt.search_type-1];
      $("<div>", {id: "search_result"}).html(
        `<span id="search_word" class="bold">${search_opt.search}</span>を含む${status.count}件の検索結果`
      ).append(
        $("<div>", {id: "page_detail"}).text(`${type}検索 ${start_num}〜${end_num}件目を表示中`)
      ).appendTo("#result_background_img");
    } else {
      $("<div>", {id: "search_result"}).text("ランダム表示（20件）").appendTo("#result_background_img");
    }

    for (let id in data) {
      const book = data[id];
      debugConsole(book);
      // リザルトリストの設定
      $('<div>', {class: 'results-list', 'data-book_id': id}).append(
        $('<div>', {class: 'img-box'}).append(
          $('<img>', {src: "./img/loading.gif", alt: unescape(book.title)})
        ),
        $('<div>', {class: 'book-info'}).append(
          $('<div>', {class: 'title'}).text(unescape(book.title)),
          $('<div>', {class: 'book-about'}).append(
            $('<span>', {class: 'author'}).text(unescape(book.author)),
            $('<span>', {class: 'publisher'}).text(unescape(book.publisher))
          ),
          $('<div>', {class: 'description'}).text(unescape(book.short_introduction)),
          $('<div>', {class: 'detail'}).append(
            $('<div>', {class: 'tag-box'}).append(
              book.tags.map(tag => $('<span>', {class: 'tag'}).text(tag))
            ),
            $('<div>', {class: 'modal-view-button', "data-id": id})
          )
        )
      ).appendTo($('#result_container'));

      if (book.is_image) {
        const img_url = `./data/book-cover/${book.isbn}.jpg`;
        $.ajax({
          url: img_url,
          type: "HEAD",
          success: () => $(`[data-book_id=${id}] .img-box img`).attr("src", img_url),
          error: () => {
            $.ajax({
              url: "./php/get-cover.php?isbn=" + book.isbn,
              type: "GET",
              success: () => $(`[data-book_id=${id}] .img-box img`).attr("src", img_url),
              error: () => $(`[data-book_id=${id}] .img-box img`).attr("src", `./img/error.png`)
            });
          }
        });
      } else {
        $(`[data-book_id=${id}] .img-box img`).attr("src", `./img/no-image.png`);
      }
    }

    // ページネーションを設定
    if ((1 <= search_opt.search_type && search_opt.search_type <= 3 || search_opt.search_type === "all") && status.count > 0) {
      const pagination = $('<div>', {class: 'pagination'});
      // 前のページがない場合はclass=disableをつける
      if (status.page == 1) {
        $('<span>', {id: 'prev', class: 'btn1 disabled'}).appendTo(pagination);
      } else {
        $('<span>', {id: 'prev', class: 'btn1', "data-page": status.page - 1}).appendTo(pagination);
      }
      // start_pageからend_pageまでの範囲を特定
      let start_page = Math.max(1, status.page - 2);
      const end_page = Math.min(Math.ceil(status.count/20), start_page + 4);
      start_page = Math.max(1, end_page - 4);
      for (let i = start_page; i <= end_page; i++) {
        if (i == status.page) {
          $('<span>', {id: 'here', class: 'btn1 bold'}).text(i).appendTo(pagination);
        } else {
          $('<span>', {class: 'btn2', "data-page": i}).text(i).appendTo(pagination);
        }
      }
      // 次のページがない場合はclass=disableをつける
      if (status.page == Math.ceil(status.count/20)) {
        $('<span>', {id: 'next', class: 'btn1 disabled'}).appendTo(pagination);
      } else {
        $('<span>', {id: 'next', class: 'btn1', "data-page": status.page + 1}).appendTo(pagination);
      }
      pagination.prependTo($("footer"));
    }

    // モーダルオープン処理
    $(".modal-view-button").on("click", function(e) {
      debugConsole(`Modal Open: ID ${$(e.target).data("id")}`, "green");
      modal_id = $(e.target).data("id");
      const book = data[modal_id];
      if (book == null) return;
      /* <=== 以下モーダル処理 ===> */
      debugConsole(`Modal Open: ID ${$(e.target).data("id")}`, "green");
      // 旧モーダルの削除
      $("#modal").remove();
      // modalの中身の作成
      $('<div>', {id: 'modal'}).append(
        $('<h1>', {class: 'modal-title'}).text(unescape(book.title)),
        $('<div>', {id: 'modal_book_about'}).append(
          $('<span>', {class: 'author'}).text(unescape(book.author)),
          $('<span>', {class: 'publisher'}).text(unescape(book.publisher)),
          $('<span>', {class: 'isbn'}).text("ISBN: " ).append(book.isbn ?? "未定義")
        ),
        $('<div>', {id: 'modal_img_box'}).append(
          $('<img>', {src: book.is_image ? `./data/book-cover/${book.isbn}.jpg` : "./img/no-image.png", alt: unescape(book.title)})
        ),
        $('<div>', {id: 'modal_book_info'}).append(
          $('<h3>', {class: 'item'}).text("概要"),
          $('<p>', {class: 'description'}).html(book.long_introduction),
          $('<h3>', {class: 'item'}).text("書籍情報"),
          $('<table>', {id: 'detail_table'}).append(
            $('<tr>', {class: 'detail_item'}).append(
              $('<td>').text("ID"),
              $('<td>').text(modal_id)
            ),
            $('<tr>', {class: 'detail_item'}).append(
              $('<td>').text("タグ"),
              $('<td>', {class: "tag-box"}).append(
                $('<label>', {id: 'edit_button'}).append(
                  $('<input>', {type: 'checkbox', id: 'edit_mode'}),
                  $('<input>', {type: 'text', id: 'tag_input', placeholder: 'タグを追加', inputmode: 'search'}),
                  $('<span>', {id: 'edit_text'}).text('編集')
                ),
                book.tags.map(tag => $('<span>', {class: 'tag'}).text(tag))
              )
            ),
            $('<tr>', {class: 'detail_item'}).append(
              $('<td>').text("出版日"),
              $('<td>').text(unescape(book.pubdate))
            )
          ),
          $('<h3>', {class: 'item'}).text("著者情報"),
          // 著者情報の配列をhtmlに変換
          Object.keys(book.authors || {}).map(author => {
            return $('<div>', {class: 'author-list'}).append(
              $('<span>', {class: 'author-name bold'}).text(author),
              $('<span>', {class: 'author-kana'}).text(book.authors[author].author_kana),
              $('<p>', {class: "author-description"}).html(book.authors[author].author_description)
            );
          })
        )
      ).appendTo($('#modal_container'));
      // スクロール位置を記憶
      scrollpos = $(window).scrollTop();
      // #modal-containerのcssを追加する
      $("body").css("overflow", "hidden");
      $("#modal_container").addClass("active");
    });

    // ページネーションのボタンを押したらページを変更する
    $(".btn1,.btn2").on("click", function (e) {
      // data-pageを取得
      const page = $(e.target).data("page");
      if (page == null) return;
      const url = new URL(location.href);
      url.searchParams.set("page", page);
      location.href = url;
    });

    console.log("読み込み完了");
    // タグ追加処理
    $(document).on("keydown", "#tag_input", function (e) {
      console.log(e.keyCode + "が押されました" + modal_id)
      if (e.keyCode == 13 && modal_id != null) {
        console.log("タグ追加処理")
        const tag = $(this).val();
        if (tag == "") return;
        $.post("./php/tag-edit.php", { id: modal_id, tag: tag, type: "add", campus: campus },function (json) {
          debugConsole(json);
          $("#tag_input").val("");
          let message = "エラーが発生しました";
          let type = 2;
          if (json.status.code == 201) {
            const listTagBox = $(`.results-list[data-book_id=${modal_id}] .tag-box`);
            const modalTagBox = $("#modal .tag-box");
            let newTag = $("<span>", { class: "tag" }).text(tag);
            listTagBox.append(newTag.clone());
            modalTagBox.append(newTag.append($("<span>", { class: "delete" })));
            data[modal_id].tags.push(tag);
            message = `タグ「${tag}」を追加しました`;
            type = 0;
          } else if (json.status.code == 204) {
            message = "動作を完了できませんでした";
            type = 1;
          }
          log(message, `${json.status.code} ${json.status.message}`, type);
          // タグ再描画のためにリサイズのトリガーを発火
          $(window).trigger("resize");
        }, "json");
      }
    });

    // タグ削除処理
    $(document).on("click", ".delete", function () {
      const $this = $(this);
      const tag = $this.parent().text();
      $.post("./php/tag-edit.php", { id: modal_id, tag: tag, type: "remove", campus: campus }, function (json) {
        debugConsole(json);
        let message = "エラーが発生しました";
        let type = 2;
        if (json.status.code == 201) {
          // modal_idと一致するdata-book_idを持つ全ての.tag-box要素を検索する
          const tagBoxes = $(`.results-list[data-book_id=${modal_id}] .tag-box,#modal .tag-box`);
          tagBoxes.find(".tag").filter(function () {
            return $(this).text() === tag;
          }).remove();
          // jsonからも削除
          data[modal_id].tags = data[modal_id].tags.filter(function (value) {
            return value != tag;
          });
          message = `タグ「${tag}」を削除しました`;
          type = 0;
        } else if (json.status.code == 204) {
          message = "動作を完了できませんでした";
          type = 1;
        }
        log(message, `${json.status.code} ${json.status.message}`, type);
        // タグ再描画のためにリサイズのトリガーを発火
        $(window).trigger("resize");
      }, "json");
    });
  });

  $.getJSON("./data/tags.json", function(data) {
    const dataList = $('<datalist>').attr('id', 'tagsList');
    data.forEach(tag => {
      dataList.append($('<option>').attr('value', tag));
    });
    $("#search_bar").after(dataList);
  });

  // エスケープを押したらモーダルを閉じる
  $("html").keydown(function(e) {
    if (e.keyCode == 27) close();
  });

  // モーダルの外側をクリックしたらモーダルを閉じる
  $("#modal_container").click(function(e) {
    if (!$(e.target).closest('#modal').length) close();
  });

  // id: searchの値をenterを押したら取得し検索する
  $("#search_bar").on("keydown", function (e) {
    if (e.key == "Enter" && lookup($("#search_bar").val().slice(0, 255).trim())) {
      const url = new URL(location.href);
      url.searchParams.delete("search");
      url.searchParams.delete("page");
      url.searchParams.set("type", "all");
      // urlを変更
      location.href = url;
    }
  });

  // #search_bar選択時、search_barの値を全選択する
  $("#search_bar").on("click", function () {
    this.setSelectionRange(0, this.value.length);
  });

  // #search_btnを押したら検索する
  $("#search_btn").on("click", function () {
    lookup($("#search_bar").val().slice(0, 255).trim());
  });

  // 検索タイプの選択処理
  $(".type-btn").click(function () {
    $(".type-btn.active").removeClass("active");
    $(this).addClass("active");
    if ($(this).data("type") == 2) {
      $("#search_bar").attr("list", "tagsList");
    } else {
      // 完全にlistを削除する
      $("#search_bar").removeAttr("list");
    }
  });

  // to_topボタンを押したら一番上に戻る
  $("#to_top").on("click", function () {
    $("html,body").animate({ scrollTop: 0 }, 300, "swing");
  });

  // タグ編集モードへの切り替え
  $(document).on("change", "#edit_mode", function () {
    const $this = $(this);
    const td = $this.closest("td");
    if ($this.prop("checked")) {
      $("#tag_input").attr("list", "tagsList");
      $("#edit_text").text("");
      td.addClass("checked");
      td.find(".tag").each(function () {
        $(this).append($("<span>", { class: "delete" }));
      });
    } else {
      td.removeClass("checked");
      td.find(".delete").remove();
      setTimeout(function () {
        // safariでlistが消えないので完全に再構築する
        $("#tag_input").replaceWith(
          $("<input>", {
            type: "text",
            id: "tag_input",
            placeholder: "タグを追加",
            inputmode: "search",
          })
        );
        $("#edit_text").text("編集");
      }, 300);
    }
  });

  // タグをクリックしたら検索する(編集モード時は無効)
  $(document).on("click", ".tag", function () {
    if (!$("#modal .tag-box.checked").length) lookup($(this).text(), 2);
  });

  // ログを閉じる
  $(document).on("click", ".log-close", function () {
    $(this).closest(".log").remove();
  });
});


$(window).on('resize', function() {
  // .results-listのouterWidthを取得
  $(".ellipsis").remove();
  $(".results-list .tag-box").each(function() {
    // 20pxはellipsisの横幅
    const tag_boxWidth = $(this).position().left + $(this).outerWidth() - 20;
    $(this).find(".tag").each(function() {
      const $this = $(this);
      const tagWidth = $this.position().left + $this.outerWidth();
      if (tagWidth > tag_boxWidth) {
        // thisの手前にellipsisを追加
        $this.before($('<span>', {class: 'ellipsis', style: `padding-right:100%;`}).text("…"));
        return false;
      }
    });
  });

  // mainのmin-heightをheaderとfooterから100vhを引いた値にする
  $("main").css("min-height", `calc(100vh - ${$("header").outerHeight() + $("footer").outerHeight()}px)`);
});

// scroll or resizeの度に実行
$(window).on('scroll resize', function() {
  const footerTop = $('footer').offset().top;
  const footerHeight = $('footer').outerHeight();
  if ($(window).scrollTop() + $(window).height() > footerTop && footerTop + footerHeight > $(window).scrollTop()) {
    $("#to_top").css("position", "absolute");
  } else {
    $("#to_top").css("position", "fixed");
  }
});
