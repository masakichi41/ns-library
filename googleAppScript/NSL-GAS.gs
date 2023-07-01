// google app script code
function DataToJson(sheetName) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();

  const sheet = ss.getSheetByName(sheetName);
  const range = sheet.getDataRange().getValues();

  let jsonStrings = '{';
  for (let i=1; i<range.length; i++) {
    let status = range[i];
    let n = setDQ(status[0].toString());
    let isbn = setDQ(getISBN(status[1].toString()));
    let title = setDQ(status[2].toString());
    jsonStrings += `${n}:{"title":${title},"isbn":${isbn}},`;
  }
  return jsonStrings.slice(0, -1) + '}';
}

function getISBN(str) {
  const regex = /\d+/g;
  let matches = str.match(regex);
  if (matches==null) return null;
  let number = matches.join("");
  if (number.length!=10 && number.length!=13) return null;
  return number;
}

function setDQ(str) {
  if (str==null) return 'null';
  //
  return `"${str.replace(/\\/g, "\\\\\\\\").replace(/\"/g, '\\"')}"`;
}

/* <=== 実行する関数 ===> */
function update_request() {
  const campus = '' // 指定されたキャンパス名
  const token = '' // 指定されたキャンパスのトークン
  const sheetName = 'シート1' // データが保存されているシート名
  const url = "https://lookup.lomo.jp/ns-library/php/setup.php";
  const options = {
    'method': 'post',
    'payload': {
      'token': token,
      'campus': campus,
      'data': DataToJson(sheetName)
    }
  }
  UrlFetchApp.fetch(url, options);
}
