/**
 * MKOnlinePlayer v2.41 → Cloudflare Worker
 * 将 PHP Meting 音乐框架和 api.php 完整转换为 JavaScript
 * 支持: 网易云/QQ/虾米/酷狗/百度/酷我 六大平台
 */

// ====================== 配置 ======================
const CONFIG = {
  HTTPS: true,
  CACHE_TTL: 86400, // KV 缓存 24 小时
  // 网易云 Cookie (如果 API 失效，请更新此处)
  NETEASE_COOKIE: 'appver=8.2.30; os=iPhone OS; osver=15.0; EVNSM=1.0.0; buildver=2206; channel=distribution; machineid=iPhone13.3',
  NETSTED_FALLBACK_COOKIE: 'MUSIC_U=; __remember_me=true; NMTID=;',
};

// ====================== MD5 实现 ======================
function md5(string) {
  function rotateLeft(lValue, iShiftBits) {
    return (lValue << iShiftBits) | (lValue >>> (32 - iShiftBits));
  }
  function addUnsigned(lX, lY) {
    const lX8 = (lX & 0x80000000);
    const lY8 = (lY & 0x80000000);
    const lX4 = (lX & 0x40000000);
    const lY4 = (lY & 0x40000000);
    const lResult = (lX & 0x3FFFFFFF) + (lY & 0x3FFFFFFF);
    if (lX4 & lY4) return lResult ^ 0x80000000 ^ lX8 ^ lY8;
    if (lX4 | lY4) {
      if (lResult & 0x40000000) return lResult ^ 0xC0000000 ^ lX8 ^ lY8;
      else return lResult ^ 0x40000000 ^ lX8 ^ lY8;
    } else {
      return lResult ^ lX8 ^ lY8;
    }
  }
  function F(x, y, z) { return (x & y) | ((~x) & z); }
  function G(x, y, z) { return (x & z) | (y & (~z)); }
  function H(x, y, z) { return (x ^ y ^ z); }
  function I(x, y, z) { return (y ^ (x | (~z))); }
  function FF(a, b, c, d, x, s, ac) {
    a = addUnsigned(a, addUnsigned(addUnsigned(F(b, c, d), x), ac));
    return addUnsigned(rotateLeft(a, s), b);
  }
  function GG(a, b, c, d, x, s, ac) {
    a = addUnsigned(a, addUnsigned(addUnsigned(G(b, c, d), x), ac));
    return addUnsigned(rotateLeft(a, s), b);
  }
  function HH(a, b, c, d, x, s, ac) {
    a = addUnsigned(a, addUnsigned(addUnsigned(H(b, c, d), x), ac));
    return addUnsigned(rotateLeft(a, s), b);
  }
  function II(a, b, c, d, x, s, ac) {
    a = addUnsigned(a, addUnsigned(addUnsigned(I(b, c, d), x), ac));
    return addUnsigned(rotateLeft(a, s), b);
  }
  function convertToWordArray(string) {
    const lWordCount = ((string.length + 8 - (string.length + 8) % 64) / 64 + 1) * 16;
    const lWordArray = new Array(lWordCount - 1);
    let lBytePosition = 0;
    for (; lBytePosition < string.length; lBytePosition++) {
      lWordArray[lBytePosition >> 2] |= (string.charCodeAt(lBytePosition) & 0xFF) << ((lBytePosition % 4) * 8);
    }
    lWordArray[lBytePosition >> 2] |= 0x80 << ((lBytePosition % 4) * 8);
    lWordArray[lWordCount - 2] = string.length << 3;
    lWordArray[lWordCount - 1] = string.length >>> 29;
    return lWordArray;
  }
  function wordToHex(lValue) {
    let wordToHexValue = '', wordToHexValueTemp = '', lByte, lCount;
    for (lCount = 0; lCount <= 3; lCount++) {
      lByte = (lValue >>> (lCount * 8)) & 255;
      wordToHexValueTemp = '0' + lByte.toString(16);
      wordToHexValue += wordToHexValueTemp.substr(wordToHexValueTemp.length - 2, 2);
    }
    return wordToHexValue;
  }
  const x = convertToWordArray(string);
  let a = 0x67452301, b = 0xEFCDAB89, c = 0x98BADCFE, d = 0x10325476;
  for (let k = 0; k < x.length; k += 16) {
    const AA = a, BB = b, CC = c, DD = d;
    a = FF(a, b, c, d, x[k + 0], 7, 0xD76AA478);
    d = FF(d, a, b, c, x[k + 1], 12, 0xE8C7B756);
    c = FF(c, d, a, b, x[k + 2], 17, 0x242070DB);
    b = FF(b, c, d, a, x[k + 3], 22, 0xC1BDCEEE);
    a = FF(a, b, c, d, x[k + 4], 7, 0xF57C0FAF);
    d = FF(d, a, b, c, x[k + 5], 12, 0x4787C62A);
    c = FF(c, d, a, b, x[k + 6], 17, 0xA8304613);
    b = FF(b, c, d, a, x[k + 7], 22, 0xFD469501);
    a = FF(a, b, c, d, x[k + 8], 7, 0x698098D8);
    d = FF(d, a, b, c, x[k + 9], 12, 0x8B44F7AF);
    c = FF(c, d, a, b, x[k + 10], 17, 0xFFFF5BB1);
    b = FF(b, c, d, a, x[k + 11], 22, 0x895CD7BE);
    a = FF(a, b, c, d, x[k + 12], 7, 0x6B901122);
    d = FF(d, a, b, c, x[k + 13], 12, 0xFD987193);
    c = FF(c, d, a, b, x[k + 14], 17, 0xA679438E);
    b = FF(b, c, d, a, x[k + 15], 22, 0x49B40821);
    a = GG(a, b, c, d, x[k + 1], 5, 0xF61E2562);
    d = GG(d, a, b, c, x[k + 6], 9, 0xC040B340);
    c = GG(c, d, a, b, x[k + 11], 14, 0x265E5A51);
    b = GG(b, c, d, a, x[k + 0], 20, 0xE9B6C7AA);
    a = GG(a, b, c, d, x[k + 5], 5, 0xD62F105D);
    d = GG(d, a, b, c, x[k + 10], 9, 0x2441453);
    c = GG(c, d, a, b, x[k + 15], 14, 0xD8A1E681);
    b = GG(b, c, d, a, x[k + 4], 20, 0xE7D3FBC8);
    a = GG(a, b, c, d, x[k + 9], 5, 0x21E1CDE6);
    d = GG(d, a, b, c, x[k + 14], 9, 0xC33707D6);
    c = GG(c, d, a, b, x[k + 3], 14, 0xF4D50D87);
    b = GG(b, c, d, a, x[k + 8], 20, 0x455A14ED);
    a = GG(a, b, c, d, x[k + 13], 5, 0xA9E3E905);
    d = GG(d, a, b, c, x[k + 2], 9, 0xFCEFA3F8);
    c = GG(c, d, a, b, x[k + 7], 14, 0x676F02D9);
    b = GG(b, c, d, a, x[k + 12], 20, 0x8D2A4C8A);
    a = HH(a, b, c, d, x[k + 5], 4, 0xFFFA3942);
    d = HH(d, a, b, c, x[k + 8], 11, 0x8771F681);
    c = HH(c, d, a, b, x[k + 11], 16, 0x6D9D6122);
    b = HH(b, c, d, a, x[k + 14], 23, 0xFDE5380C);
    a = HH(a, b, c, d, x[k + 1], 4, 0xA4BEEA44);
    d = HH(d, a, b, c, x[k + 4], 11, 0x4BDECFA9);
    c = HH(c, d, a, b, x[k + 7], 16, 0xF6BB4B60);
    b = HH(b, c, d, a, x[k + 10], 23, 0xBEBFBC70);
    a = HH(a, b, c, d, x[k + 13], 4, 0x289B7EC6);
    d = HH(d, a, b, c, x[k + 0], 11, 0xEAA127FA);
    c = HH(c, d, a, b, x[k + 3], 16, 0xD4EF3085);
    b = HH(b, c, d, a, x[k + 6], 23, 0x4881D05);
    a = HH(a, b, c, d, x[k + 9], 4, 0xD9D4D039);
    d = HH(d, a, b, c, x[k + 12], 11, 0xE6DB99E5);
    c = HH(c, d, a, b, x[k + 15], 16, 0x1FA27CF8);
    b = HH(b, c, d, a, x[k + 2], 23, 0xC4AC5665);
    a = II(a, b, c, d, x[k + 0], 6, 0xF4292244);
    d = II(d, a, b, c, x[k + 7], 10, 0x432AFF97);
    c = II(c, d, a, b, x[k + 14], 15, 0xAB9423A7);
    b = II(b, c, d, a, x[k + 5], 21, 0xFC93A039);
    a = II(a, b, c, d, x[k + 12], 6, 0x655B59C3);
    d = II(d, a, b, c, x[k + 3], 10, 0x8F0CCC92);
    c = II(c, d, a, b, x[k + 10], 15, 0xFFEFF47D);
    b = II(b, c, d, a, x[k + 1], 21, 0x85845DD1);
    a = II(a, b, c, d, x[k + 8], 6, 0x6FA87E4F);
    d = II(d, a, b, c, x[k + 15], 10, 0xFE2CE6E0);
    c = II(c, d, a, b, x[k + 6], 15, 0xA3014314);
    b = II(b, c, d, a, x[k + 13], 21, 0x4E0811A1);
    a = II(a, b, c, d, x[k + 4], 6, 0xF7537E82);
    d = II(d, a, b, c, x[k + 11], 10, 0xBD3AF235);
    c = II(c, d, a, b, x[k + 2], 15, 0x2AD7D2BB);
    b = II(b, c, d, a, x[k + 9], 21, 0xEB86D391);
    a = addUnsigned(a, AA);
    b = addUnsigned(b, BB);
    c = addUnsigned(c, CC);
    d = addUnsigned(d, DD);
  }
  return (wordToHex(a) + wordToHex(b) + wordToHex(c) + wordToHex(d)).toLowerCase();
}

// ====================== AES-128-CBC 加密 ======================
async function aesEncrypt(text, key, iv) {
  const encoder = new TextEncoder();
  const data = encoder.encode(text);
  const keyBuffer = encoder.encode(key);
  const ivBuffer = encoder.encode(iv);

  const cryptoKey = await crypto.subtle.importKey(
    'raw', keyBuffer, { name: 'AES-CBC' }, false, ['encrypt']
  );
  const encrypted = await crypto.subtle.encrypt(
    { name: 'AES-CBC', iv: ivBuffer }, cryptoKey, data
  );
  return btoa(String.fromCharCode(...new Uint8Array(encrypted)));
}

// ====================== 网易云加密 ID (用于封面) ======================
function neteaseEncryptId(id) {
  const magic = '3go8&$8*3*3h0k(2)2'.split('');
  const songId = String(id).split('');
  for (let i = 0; i < songId.length; i++) {
    songId[i] = String.fromCharCode(songId[i].charCodeAt(0) ^ magic[i % magic.length].charCodeAt(0));
  }
  // md5 with raw binary output → base64
  const hash = md5(songId.join(''));
  // Convert hex to base64 (simplified - md5 hex to base64)
  const hexBytes = hash.match(/.{2}/g).map(b => String.fromCharCode(parseInt(b, 16))).join('');
  let result = btoa(hexBytes);
  result = result.replace(/\//g, '_').replace(/\+/g, '-');
  return result;
}

// ====================== 随机 Hex ======================
function getRandomHex(length) {
  const bytes = new Uint8Array(length / 2);
  crypto.getRandomValues(bytes);
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

// ====================== HTTP 请求工具 ======================
async function httpRequest(url, options = {}) {
  const { method = 'GET', body, headers = {}, isJson = true } = options;

  const fetchOptions = {
    method,
    headers: new Headers(headers),
  };

  if (body) {
    if (typeof body === 'string') {
      fetchOptions.body = body;
    } else if (body instanceof URLSearchParams) {
      fetchOptions.body = body.toString();
    } else if (typeof body === 'object') {
      fetchOptions.body = new URLSearchParams(body).toString();
    }
  }

  // Retry up to 3 times
  for (let i = 0; i < 3; i++) {
    try {
      const response = await fetch(url, fetchOptions);
      const text = await response.text();
      return text;
    } catch (e) {
      if (i === 2) throw e;
    }
  }
}

// ====================== NetEase AES 加密 (weapi) ======================
async function neteaseAESCBC(body) {
  const nonce = '0CoJUm6Qyw8W8jud';
  const vi = '0102030405060708';
  const skey = 'B3v3kH4vRPWRJFfH'; // 无 bcmath 时使用固定值
  // encSecKey 固定值 (对应固定 skey)
  const encSecKey = '85302b818aea19b68db899c25dac229412d9bba9b3fcfe4f714dc016bc1686fc446a08844b1f8327fd9cb623cc189be00c5a365ac835e93d4858ee66f43fdc59e32aaed3ef24f0675d70172ef688d376a4807228c55583fe5bac647d10ecef15220feef61477c28cae8406f6f9896ed329d6db9f88757e31848a6c2ce2f94308';

  const jsonBody = JSON.stringify(body);

  // 第一层: AES-128-CBC with nonce
  let encrypted = await aesEncrypt(jsonBody, nonce, vi);
  // 第二层: AES-128-CBC with skey
  encrypted = await aesEncrypt(encrypted, skey, vi);

  return {
    params: encrypted,
    encSecKey: encSecKey,
  };
}

// ====================== BaiDu AES 加密 ======================
async function baiduAESCBC(body) {
  const key = 'DBEECF8C50FD160E';
  const vi = '1231021386755796';
  const data = `songid=${body.songid}&ts=${Date.now()}`;
  const e = await aesEncrypt(data, key, vi);
  body.e = e;
  return body;
}

// ====================== Xiami 签名 ======================
async function xiamiSign(api) {
  // 先获取 cookie
  const cookieUrl = 'https://acs.m.xiami.com/h5/mtop.alimusic.recommend.songservice.getdailysongs/1.0/?appKey=12574478&t=1560663823000&dataType=json&data=%7B%22requestStr%22%3A%22%7B%5C%22header%5C%22%3A%7B%5C%22platformId%5C%22%3A%5C%22mac%5C%22%7D%2C%5C%22model%5C%22%3A%5B%5D%7D%22%7D&api=mtop.alimusic.recommend.songservice.getdailysongs&v=1.0&type=originaljson&sign=22ad1377ee193f3e2772c17c6192b17c';
  const cookieResp = await httpRequest(cookieUrl, { method: 'GET' });

  // 提取 cookie
  const cookieMatch = cookieResp.match(/_m_h5[^;]+/g);
  const cookieHeader = cookieMatch ? cookieMatch[0] + '; ' + (cookieMatch[1] || '') : '';

  const appkey = '12574478';
  const tokenMatch = cookieHeader.match(/_m_h5_tk=([^_]+)/);
  const token = tokenMatch ? tokenMatch[1] : '';

  const dataObj = {
    requestStr: JSON.stringify({
      header: { platformId: 'mac' },
      model: api.body.data,
    }),
  };
  const dataStr = JSON.stringify(dataObj);
  const t = Date.now();
  const sign = md5(`${token}&${t}&${appkey}&${dataStr}`);

  return {
    appKey: appkey,
    t: t,
    dataType: 'json',
    data: dataStr,
    api: api.body.r,
    v: '1.0',
    type: 'originaljson',
    sign: sign,
  };
}

// ====================== Headers 配置 ======================
function getHeaders(server) {
  switch (server) {
    case 'netease':
      return {
        'Referer': 'https://music.163.com/',
        'Cookie': CONFIG.NETEASE_COOKIE,
        'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 CloudMusic/0.1.1 NeteaseMusic/8.2.30',
        'Accept': '*/*',
        'Accept-Language': 'zh-CN,zh;q=0.8,gl;q=0.6,zh-TW;q=0.4',
        'Connection': 'keep-alive',
        'Content-Type': 'application/x-www-form-urlencoded',
      };
    case 'tencent':
      return {
        'Referer': 'http://y.qq.com',
        'Cookie': 'pgv_pvi=22038528; pgv_si=s3156287488; pgv_pvid=5535248600; yplayer_open=1; ts_last=y.qq.com/portal/player.html; ts_uid=4847550686; yq_index=0; qqmusic_fromtag=66; player_exist=1',
        'User-Agent': 'QQ%E9%9F%B3%E4%B9%90/54409 CFNetwork/901.1 Darwin/17.6.0 (x86_64)',
        'Accept': '*/*',
        'Accept-Language': 'zh-CN,zh;q=0.8,gl;q=0.6,zh-TW;q=0.4',
        'Connection': 'keep-alive',
        'Content-Type': 'application/x-www-form-urlencoded',
      };
    case 'xiami':
      return {
        'Cookie': '_m_h5_tk=15d3402511a022796d88b249f83fb968_1511163656929; _m_h5_tk_enc=b6b3e64d81dae577fc314b5c5692df3c',
        'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_5) AppleWebKit/537.36 (KHTML, like Gecko) XIAMI-MUSIC/3.1.1 Chrome/56.0.2924.87 Electron/1.6.11 Safari/537.36',
        'Accept': 'application/json',
        'Content-type': 'application/x-www-form-urlencoded',
        'Accept-Language': 'zh-CN',
      };
    case 'kugou':
      return {
        'User-Agent': 'IPhone-8990-searchSong',
        'UNI-UserAgent': 'iOS11.4-Phone8990-1009-0-WiFi',
      };
    case 'baidu':
      return {
        'Cookie': 'BAIDUID=' + getRandomHex(32) + ':FG=1',
        'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) baidu-music/1.2.1 Chrome/66.0.3359.181 Electron/3.0.5 Safari/537.36',
        'Accept': '*/*',
        'Content-type': 'application/json;charset=UTF-8',
        'Accept-Language': 'zh-CN',
      };
    case 'kuwo':
      return {
        'Cookie': 'Hm_lvt_cdb524f42f0ce19b169a8071123a4797=1623339177,1623339183; _ga=GA1.2.1195980605.1579367081; Hm_lpvt_cdb524f42f0ce19b169a8071123a4797=1623339982; kw_token=3E7JFQ7MRPL; _gid=GA1.2.747985028.1623339179; _gat=1',
        'csrf': '3E7JFQ7MRPL',
        'Host': 'www.kuwo.cn',
        'Referer': 'http://www.kuwo.cn/',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.77 Safari/537.36',
      };
    default:
      return {};
  }
}

// ====================== 核心 API 执行 ======================
async function execApi(server, apiDef) {
  let api = { ...apiDef };
  let body = api.body;
  let headers = getHeaders(server);

  // Encode step
  if (api.encode) {
    if (api.encode === 'netease_AESCBC') {
      body = await neteaseAESCBC(api.body);
      api.url = api.url.replace('/api/', '/weapi/');
    } else if (api.encode === 'baidu_AESCBC') {
      body = await baiduAESCBC({ ...api.body });
    } else if (api.encode === 'xiami_sign') {
      body = await xiamiSign(api);
    }
  }

  // Build URL for GET requests
  let url = api.url;
  const method = api.method || 'GET';

  if (method === 'GET' && body && api.encode !== 'netease_AESCBC') {
    const params = new URLSearchParams(body);
    url += (url.includes('?') ? '&' : '?') + params.toString();
    body = null;
  }

  let fetchBody = null;
  if (body) {
    if (api.encode === 'netease_AESCBC') {
      fetchBody = new URLSearchParams(body).toString();
    } else if (typeof body === 'object') {
      fetchBody = new URLSearchParams(body).toString();
    } else {
      fetchBody = body;
    }
  }

  const result = await httpRequest(url, {
    method: method,
    body: fetchBody,
    headers: headers,
  });

  // Decode step
  if (api.decode) {
    return decodeResult(server, api.decode, result);
  }

  // Format step
  if (api.format) {
    return formatResult(server, api.format, result);
  }

  return result;
}

// ====================== 数据格式化 ======================
function pickup(array, rule) {
  const keys = rule.split('.');
  let result = array;
  for (const key of keys) {
    if (!result || result[key] === undefined) return [];
    result = result[key];
  }
  return result;
}

function formatResult(server, rule, raw) {
  const data = JSON.parse(raw);
  let list = [];
  if (rule) {
    list = pickup(data, rule);
  } else {
    list = data;
  }
  if (!Array.isArray(list)) {
    list = list ? [list] : [];
  }
  return JSON.stringify(list.map(item => formatItem(server, item)));
}

const formatters = {
  netease: (data) => ({
    id: data.id,
    name: data.name,
    artist: (data.ar || []).map(v => v.name),
    album: data.al ? data.al.name : '',
    pic_id: data.al && data.al.picUrl ? data.al.picUrl.match(/\/(\d+)\./)?.[1] || data.al.pic_str || data.al.pic : (data.al ? (data.al.pic_str || data.al.pic) : ''),
    url_id: data.id,
    lyric_id: data.id,
    source: 'netease',
  }),
  tencent: (data) => {
    const d = data.musicData || data;
    return {
      id: d.mid,
      name: d.name,
      artist: (d.singer || []).map(v => v.name),
      album: (d.album && d.album.title) ? d.album.title.trim() : '',
      pic_id: d.album ? d.album.mid : '',
      url_id: d.mid,
      lyric_id: d.mid,
      source: 'tencent',
    };
  },
  xiami: (data) => ({
    id: data.songId,
    name: data.songName,
    artist: (data.singerVOs || []).map(v => v.artistName),
    album: data.albumName || '',
    pic_id: data.songId,
    url_id: data.songId,
    lyric_id: data.songId,
    source: 'xiami',
  }),
  kugou: (data) => {
    const filename = data.filename || data.fileName || '';
    const parts = filename.split(' - ');
    return {
      id: data.hash,
      name: parts[1] || filename,
      artist: (parts[0] || '').split('、'),
      album: data.album_name || '',
      url_id: data.hash,
      pic_id: data.hash,
      lyric_id: data.hash,
      source: 'kugou',
    };
  },
  baidu: (data) => ({
    id: data.song_id,
    name: data.title,
    artist: (data.author || '').split(','),
    album: data.album_title || '',
    pic_id: data.song_id,
    url_id: data.song_id,
    lyric_id: data.song_id,
    source: 'baidu',
  }),
  kuwo: (data) => ({
    id: data.rid,
    name: data.name,
    artist: (data.artist || '').split(','),
    album: data.album || '',
    pic_id: data.rid,
    url_id: data.rid,
    lyric_id: data.rid,
    source: 'kuwo',
  }),
};

function formatItem(server, data) {
  if (formatters[server]) {
    return formatters[server](data);
  }
  return data;
}

// ====================== URL / Lyric 解码 ======================
async function decodeResult(server, decodeType, result) {
  const data = JSON.parse(result);

  switch (decodeType) {
    case 'netease_url': {
      const d = data.data && data.data[0];
      if (d && d.uf && d.uf.url) d.url = d.uf.url;
      if (d && d.url) {
        return JSON.stringify({ url: d.url, size: d.size || 0, br: (d.br || 0) / 1000 });
      }
      return JSON.stringify({ url: '', size: 0, br: -1 });
    }
    case 'tencent_url': {
      const d = data.data && data.data[0];
      if (!d) return JSON.stringify({ url: '', size: 0, br: -1 });

      const guid = Math.floor(Math.random() * 10000000000);
      const uin = '0';

      const typeList = [
        ['size_flac', 999, 'F000', 'flac'],
        ['size_320mp3', 320, 'M800', 'mp3'],
        ['size_192aac', 192, 'C600', 'm4a'],
        ['size_128mp3', 128, 'M500', 'mp3'],
        ['size_96aac', 96, 'C400', 'm4a'],
        ['size_48aac', 48, 'C200', 'm4a'],
        ['size_24aac', 24, 'C100', 'm4a'],
      ];

      const payload = {
        req_0: {
          module: 'vkey.GetVkeyServer',
          method: 'CgiGetVkey',
          param: {
            guid: String(guid),
            songmid: typeList.map(() => d.mid),
            filename: typeList.map(t => t[2] + d.file.media_mid + '.' + t[3]),
            songtype: typeList.map(() => d.type),
            uin: uin,
            loginflag: 1,
            platform: '20',
          },
        },
      };

      const vkeyResp = await httpRequest(
        `https://u.y.qq.com/cgi-bin/musicu.fcg?format=json&platform=yqq.json&needNewCode=0&data=${encodeURIComponent(JSON.stringify(payload))}`,
        { method: 'GET', headers: getHeaders('tencent') }
      );
      const vkeyData = JSON.parse(vkeyResp);
      const vkeys = vkeyData.req_0.data.midurlinfo;

      for (let i = 0; i < typeList.length; i++) {
        const fileKey = typeList[i][0];
        const bitrate = typeList[i][1];
        if (d.file[fileKey] && bitrate <= 320) {
          if (vkeys[i] && vkeys[i].vkey) {
            return JSON.stringify({
              url: vkeyData.req_0.data.sip[0] + vkeys[i].purl,
              size: d.file[fileKey],
              br: bitrate,
            });
          }
        }
      }
      return JSON.stringify({ url: '', size: 0, br: -1 });
    }
    case 'xiami_url': {
      const songs = pickup(data, 'data.data.songs');
      if (!songs || !songs[0]) return JSON.stringify({ url: '', size: 0, br: -1 });
      const files = songs[0].listenFiles || [];
      const typeMap = { s: 740, h: 320, l: 128, f: 64, e: 32 };
      let max = 0, best = {};
      for (const f of files) {
        const br = typeMap[f.quality] || 0;
        if (br <= 320 && br > max) {
          max = br;
          best = { url: f.listenFile, size: f.fileSize, br: br };
        }
      }
      return JSON.stringify(best.url ? best : { url: '', size: 0, br: -1 });
    }
    case 'kugou_url': {
      const goods = pickup(data, 'data');
      if (!goods || !goods[0]) return JSON.stringify({ url: '', size: 0, br: -1 });
      let max = 0, best = {};
      for (const g of (goods[0].relate_goods || [])) {
        if (g.info.bitrate <= 320 && g.info.bitrate > max) {
          const trackerResp = await httpRequest(
            `http://trackercdn.kugou.com/i/v2/?hash=${g.hash}&key=${md5(g.hash + 'kgcloudv2')}&pid=3&behavior=play&cmd=25&version=8990`,
            { method: 'GET' }
          );
          const t = JSON.parse(trackerResp);
          if (t.url) {
            max = t.bitRate / 1000;
            best = { url: Object.values(t.url)[0], size: t.fileSize, br: t.bitRate / 1000 };
          }
        }
      }
      return JSON.stringify(best.url ? best : { url: '', size: 0, br: -1 });
    }
    case 'baidu_url': {
      const urls = pickup(data, 'songurl.url') || [];
      let max = 0, best = {};
      for (const u of urls) {
        if (u.file_bitrate <= 320 && u.file_bitrate > max) {
          max = u.file_bitrate;
          best = { url: u.file_link, br: u.file_bitrate };
        }
      }
      return JSON.stringify(best.url ? best : { url: '', br: -1 });
    }
    case 'kuwo_url': {
      if (data.code === 200 && data.url) {
        return JSON.stringify({ url: data.url, br: 128 });
      }
      return JSON.stringify({ url: '', br: -1 });
    }
    case 'netease_lyric': {
      return JSON.stringify({
        lyric: (data.lrc && data.lrc.lyric) ? data.lrc.lyric : '',
        tlyric: (data.tlyric && data.tlyric.lyric) ? data.tlyric.lyric : '',
      });
    }
    case 'tencent_lyric': {
      // Remove JSONP wrapper
      const cleaned = result.substring(18, result.length - 1);
      const d = JSON.parse(cleaned);
      return JSON.stringify({
        lyric: d.lyric ? atob(d.lyric) : '',
        tlyric: d.trans ? atob(d.trans) : '',
      });
    }
    case 'xiami_lyric': {
      const lyrics = pickup(data, 'data.data.lyrics');
      if (lyrics && lyrics.length) {
        let content = lyrics[0].content || '';
        content = content.replace(/<[^>]+>/g, '');
        const matches = [...content.matchAll(/\[([\d:\.]+)\](.*)\s\[x-trans\](.*)/gi)];
        if (matches.length) {
          const A = [], B = [];
          for (const m of matches) {
            A.push(`[${m[1]}]${m[2]}`);
            B.push(`[${m[1]}]${m[3]}`);
          }
          const origTexts = matches.map(m => m[0]);
          return JSON.stringify({
            lyric: origTexts.reduce((acc, orig, i) => acc.replace(orig, A[i]), content),
            tlyric: origTexts.reduce((acc, orig, i) => acc.replace(orig, B[i]), content),
          });
        }
        return JSON.stringify({ lyric: content, tlyric: '' });
      }
      return JSON.stringify({ lyric: '', tlyric: '' });
    }
    case 'kugou_lyric': {
      const candidates = pickup(data, 'candidates');
      if (candidates && candidates[0]) {
        const lyricResp = await httpRequest(
          `http://lyrics.kugou.com/download?charset=utf8&accesskey=${candidates[0].accesskey}&id=${candidates[0].id}&client=mobi&fmt=lrc&ver=1`,
          { method: 'GET' }
        );
        const lrc = JSON.parse(lyricResp);
        return JSON.stringify({
          lyric: lrc.content ? atob(lrc.content) : '',
          tlyric: '',
        });
      }
      return JSON.stringify({ lyric: '', tlyric: '' });
    }
    case 'baidu_lyric': {
      return JSON.stringify({
        lyric: data.lrcContent || '',
        tlyric: '',
      });
    }
    case 'kuwo_lyric': {
      const lrcList = pickup(data, 'data.lrclist');
      if (lrcList && lrcList.length) {
        let kuwolrc = '';
        for (const item of lrcList) {
          const otime = item.time;
          const parts = otime.split('.');
          const osec = parseInt(parts[0]);
          const min = String(Math.floor(osec / 60)).padStart(2, '0');
          const sec = String(osec - min * 60).padStart(2, '0');
          const msec = parts[1] || '00';
          kuwolrc += `[${min}:${sec}.${msec}]${item.lineLyric}\n`;
        }
        return JSON.stringify({ lyric: kuwolrc, tlyric: '' });
      }
      return JSON.stringify({ lyric: '', tlyric: '' });
    }
    default:
      return result;
  }
}

// ====================== API 定义 ======================
const API_DEFS = {
  search: {
    netease: (keyword, option = {}) => ({
      method: 'POST',
      url: 'http://music.163.com/api/cloudsearch/pc',
      body: {
        s: keyword,
        type: option.type || 1,
        limit: option.limit || 30,
        total: 'true',
        offset: (option.page && option.limit) ? (option.page - 1) * option.limit : 0,
      },
      encode: 'netease_AESCBC',
      format: 'result.songs',
    }),
    tencent: (keyword, option = {}) => ({
      method: 'GET',
      url: 'https://c.y.qq.com/soso/fcgi-bin/client_search_cp',
      body: {
        format: 'json',
        p: option.page || 1,
        n: option.limit || 30,
        w: keyword,
        aggr: 1,
        lossless: 1,
        cr: 1,
        new_json: 1,
      },
      format: 'data.song.list',
    }),
    xiami: (keyword, option = {}) => ({
      method: 'GET',
      url: 'https://acs.m.xiami.com/h5/mtop.alimusic.search.searchservice.searchsongs/1.0/',
      body: {
        data: {
          key: keyword,
          pagingVO: { page: option.page || 1, pageSize: option.limit || 30 },
        },
        r: 'mtop.alimusic.search.searchservice.searchsongs',
      },
      encode: 'xiami_sign',
      format: 'data.data.songs',
    }),
    kugou: (keyword, option = {}) => ({
      method: 'GET',
      url: 'http://mobilecdn.kugou.com/api/v3/search/song',
      body: {
        api_ver: 1,
        area_code: 1,
        correct: 1,
        pagesize: option.limit || 30,
        plat: 2,
        tag: 1,
        sver: 5,
        showtype: 10,
        page: option.page || 1,
        keyword: keyword,
        version: 8990,
      },
      format: 'data.info',
    }),
    baidu: (keyword, option = {}) => ({
      method: 'GET',
      url: 'http://musicapi.taihe.com/v1/restserver/ting',
      body: {
        from: 'qianqianmini',
        method: 'baidu.ting.search.merge',
        isNew: 1,
        platform: 'darwin',
        page_no: option.page || 1,
        query: keyword,
        version: '11.2.1',
        page_size: option.limit || 30,
      },
      format: 'result.song_info.song_list',
    }),
    kuwo: (keyword, option = {}) => ({
      method: 'GET',
      url: 'http://www.kuwo.cn/api/www/search/searchMusicBykeyWord',
      body: {
        key: keyword,
        pn: option.page || 1,
        rn: option.limit || 30,
        httpsStatus: 1,
      },
      format: 'data.list',
    }),
  },
};

// ====================== 路由处理 ======================
async function handleApiRequest(url, request) {
  const params = url.searchParams;
  const types = params.get('types') || '';
  const source = params.get('source') || 'netease';
  const id = params.get('id') || '';
  const callback = params.get('callback') || '';
  const HTTPS = CONFIG.HTTPS;

  let result = '';

  try {
    switch (types) {
      case 'url': {
        const apiDef = getUrlApi(source, id);
        result = await execApi(source, apiDef);
        break;
      }
      case 'pic': {
        result = await getPic(source, id);
        break;
      }
      case 'lyric': {
        const apiDef = getLyricApi(source, id);
        result = await execApi(source, apiDef);
        break;
      }
      case 'search': {
        const keyword = params.get('name') || '';
        const limit = parseInt(params.get('count')) || 20;
        const page = parseInt(params.get('pages')) || 1;
        const apiDef = API_DEFS.search[source]
          ? API_DEFS.search[source](keyword, { page, limit })
          : API_DEFS.search['netease'](keyword, { page, limit });
        result = await execApi(source, apiDef);
        break;
      }
      case 'playlist': {
        result = await getPlaylist(source, id);
        break;
      }
      case 'userlist': {
        const uid = params.get('uid') || '';
        const resp = await httpRequest(
          `http://music.163.com/api/user/playlist/?offset=0&limit=1001&uid=${uid}`,
          { method: 'GET', headers: getHeaders('netease') }
        );
        result = resp;
        break;
      }
      case 'download': {
        // 已弃用
        const fileurl = params.get('url') || '';
        return Response.redirect(fileurl, 302);
      }
      default: {
        // 调试信息
        return new Response(
          `<html><head><meta charset="utf-8"><title>MKOnlinePlayer CF Worker</title></head><body>
          <h2>MKOnlinePlayer v2.41 - Cloudflare Worker</h2>
          <p>API 运行正常。支持平台: netease, tencent, xiami, kugou, baidu, kuwo</p>
          <p>用法: ?types=search&source=netease&name=关键词</p>
          </body></html>`,
          { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
      }
    }

    // HTTPS 转换
    if (HTTPS && source !== 'kugou' && source !== 'baidu') {
      result = result.replace(/http:\/\//g, 'https://');
    }

    // JSONP 支持
    if (callback) {
      return new Response(`${callback}(${result})`, {
        headers: {
          'Content-Type': 'application/javascript; charset=utf-8',
          'Access-Control-Allow-Origin': '*',
        },
      });
    }

    return new Response(result, {
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin': '*',
      },
    });

  } catch (err) {
    const errorResult = JSON.stringify({ error: err.message });
    if (callback) {
      return new Response(`${callback}(${errorResult})`, {
        headers: {
          'Content-Type': 'application/javascript; charset=utf-8',
          'Access-Control-Allow-Origin': '*',
        },
      });
    }
    return new Response(errorResult, {
      status: 500,
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin': '*',
      },
    });
  }
}

// ====================== URL API ======================
function getUrlApi(source, id) {
  switch (source) {
    case 'netease':
      return {
        method: 'POST',
        url: 'http://music.163.com/api/song/enhance/player/url',
        body: { ids: [id], br: 320000 },
        encode: 'netease_AESCBC',
        decode: 'netease_url',
      };
    case 'tencent':
      return {
        method: 'GET',
        url: 'https://c.y.qq.com/v8/fcg-bin/fcg_play_single_song.fcg',
        body: { songmid: id, platform: 'yqq', format: 'json' },
        decode: 'tencent_url',
      };
    case 'xiami':
      return {
        method: 'GET',
        url: 'https://acs.m.xiami.com/h5/mtop.alimusic.music.songservice.getsongs/1.0/',
        body: { data: { songIds: [id] }, r: 'mtop.alimusic.music.songservice.getsongs' },
        encode: 'xiami_sign',
        decode: 'xiami_url',
      };
    case 'kugou':
      return {
        method: 'POST',
        url: 'http://media.store.kugou.com/v1/get_res_privilege',
        body: JSON.stringify({
          relate: 1, userid: '0', vip: 0, appid: 1000, token: '',
          behavior: 'download', area_code: '1', clientver: '8990',
          resource: [{ id: 0, type: 'audio', hash: id }],
        }),
        decode: 'kugou_url',
      };
    case 'baidu':
      return {
        method: 'GET',
        url: 'http://musicapi.taihe.com/v1/restserver/ting',
        body: { from: 'qianqianmini', method: 'baidu.ting.song.getInfos', songid: id, res: 1, platform: 'darwin', version: '1.0.0' },
        encode: 'baidu_AESCBC',
        decode: 'baidu_url',
      };
    case 'kuwo':
      return {
        method: 'GET',
        url: 'http://www.kuwo.cn/url',
        body: { rid: id, response: 'url', type: 'convert_url3', br: '128kmp3', from: 'web', t: Math.floor(Date.now() / 1000), httpsStatus: 1 },
        decode: 'kuwo_url',
      };
    default:
      return getUrlApi('netease', id);
  }
}

// ====================== Lyric API ======================
function getLyricApi(source, id) {
  switch (source) {
    case 'netease':
      return {
        method: 'POST',
        url: 'http://music.163.com/api/song/lyric',
        body: { id, os: 'linux', lv: -1, kv: -1, tv: -1 },
        encode: 'netease_AESCBC',
        decode: 'netease_lyric',
      };
    case 'tencent':
      return {
        method: 'GET',
        url: 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg',
        body: { songmid: id, g_tk: '5381' },
        decode: 'tencent_lyric',
      };
    case 'xiami':
      return {
        method: 'GET',
        url: 'https://acs.m.xiami.com/h5/mtop.alimusic.music.lyricservice.getsonglyrics/1.0/',
        body: { data: { songId: id }, r: 'mtop.alimusic.music.lyricservice.getsonglyrics' },
        encode: 'xiami_sign',
        decode: 'xiami_lyric',
      };
    case 'kugou':
      return {
        method: 'GET',
        url: 'http://krcs.kugou.com/search',
        body: { keyword: '%20-%20', ver: 1, hash: id, client: 'mobi', man: 'yes' },
        decode: 'kugou_lyric',
      };
    case 'baidu':
      return {
        method: 'GET',
        url: 'http://musicapi.taihe.com/v1/restserver/ting',
        body: { from: 'qianqianmini', method: 'baidu.ting.song.lry', songid: id, platform: 'darwin', version: '1.0.0' },
        decode: 'baidu_lyric',
      };
    case 'kuwo':
      return {
        method: 'GET',
        url: 'http://m.kuwo.cn/newh5/singles/songinfoandlrc',
        body: { musicId: id, httpsStatus: 1 },
        decode: 'kuwo_lyric',
      };
    default:
      return getLyricApi('netease', id);
  }
}

// ====================== Pic API ======================
async function getPic(source, id, size = 300) {
  let url = '';
  switch (source) {
    case 'netease':
      url = `https://p3.music.126.net/${neteaseEncryptId(id)}/${id}.jpg?param=${size}y${size}`;
      break;
    case 'tencent':
      url = `https://y.gtimg.cn/music/photo_new/T002R${size}x${size}M000${id}.jpg?max_age=2592000`;
      break;
    case 'xiami': {
      const songApi = {
        method: 'GET',
        url: 'https://acs.m.xiami.com/h5/mtop.alimusic.music.songservice.getsongdetail/1.0/',
        body: { data: { songId: id }, r: 'mtop.alimusic.music.songservice.getsongdetail' },
        encode: 'xiami_sign',
      };
      const songData = await execApi(source, songApi);
      const parsed = JSON.parse(songData);
      url = pickup(parsed, 'data.data.songDetail.albumLogo') || '';
      if (url) url = url.replace('http:', 'https:') + `@1e_1c_100Q_${size}h_${size}w`;
      break;
    }
    case 'kugou': {
      const songApi = {
        method: 'POST',
        url: 'http://m.kugou.com/app/i/getSongInfo.php',
        body: { cmd: 'playInfo', hash: id, from: 'mkugou' },
      };
      const songData = await execApi(source, songApi);
      const parsed = JSON.parse(songData);
      url = (parsed.imgUrl || '').replace('{size}', '400');
      break;
    }
    case 'baidu': {
      const songApi = {
        method: 'GET',
        url: 'http://musicapi.taihe.com/v1/restserver/ting',
        body: { from: 'qianqianmini', method: 'baidu.ting.song.getInfos', songid: id, res: 1, platform: 'darwin', version: '1.0.0' },
        encode: 'baidu_AESCBC',
      };
      const songData = await execApi(source, songApi);
      const parsed = JSON.parse(songData);
      url = pickup(parsed, 'songinfo.pic_radio') || pickup(parsed, 'songinfo.pic_small') || '';
      break;
    }
    case 'kuwo': {
      const songApi = {
        method: 'GET',
        url: 'http://www.kuwo.cn/api/www/music/musicInfo',
        body: { mid: id, httpsStatus: 1 },
      };
      const songData = await execApi(source, songApi);
      const parsed = JSON.parse(songData);
      url = pickup(parsed, 'data.pic') || pickup(parsed, 'data.albumpic') || '';
      break;
    }
  }
  return JSON.stringify({ url });
}

// ====================== Playlist API ======================
async function getPlaylist(source, id) {
  // 歌单目前只用 netease 格式（前端依赖 netease 的数据结构）
  const apiDef = {
    method: 'POST',
    url: 'http://music.163.com/api/v6/playlist/detail',
    body: { s: '0', id: id, n: '1000', t: '0' },
    encode: 'netease_AESCBC',
  };
  return await execApi('netease', apiDef);
}

// ====================== Get.php 备用 API ======================
async function handleGetApi(url, request) {
  const params = url.searchParams;
  const id = params.get('id') || '';
  const type = params.get('type') || 'song';
  const media = params.get('media') || 'netease';

  try {
    let data;
    if (type === 'search') {
      const page = params.get('page') || '1';
      const limit = params.get('limit') || '30';
      const apiDef = API_DEFS.search[media]
        ? API_DEFS.search[media](id, { page: parseInt(page), limit: parseInt(limit) })
        : API_DEFS.search['netease'](id, { page: parseInt(page), limit: parseInt(limit) });
      data = await execApi(media, apiDef);
    } else if (type === 'url') {
      const songApi = getUrlApi(media, id);
      songApi.encode = undefined; // raw format
      data = await execApi(media, songApi);
    } else {
      // song, album, artist, playlist
      const apiDef = getGenericApi(media, type, id);
      data = await execApi(media, apiDef);
    }

    const items = JSON.parse(data);
    if (!Array.isArray(items)) {
      return new Response(JSON.stringify([]), {
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
      });
    }

    const result = [];
    for (const item of items) {
      const coverResp = await getPic(media, item.pic_id);
      const coverUrl = JSON.parse(coverResp).url || '';

      let url = '';
      try {
        const urlResp = getUrlApi(media, item.url_id);
        const urlData = await execApi(media, urlResp);
        url = JSON.parse(urlData).url || '';
      } catch (e) { /* ignore */ }

      // 网易云防盗链修复
      if (media === 'netease') {
        url = url.replace('://m7c.', '://m7.');
        url = url.replace('://m8c.', '://m8.');
        url = url.replace('http://m10c.', 'https://m10.');
        url = url.replace('http://m701c.', 'https://m701.');
        url = url.replace('http://m801c.', 'https://m801.');
        url = url.replace('https://other.', 'http://other.');
      }
      // QQ音乐 ws 格式修复
      if (media === 'tencent') {
        url = url.replace('//ws', '//isure');
      }
      if (CONFIG.HTTPS) {
        url = url.replace(/http:\/\//g, 'https://');
      }

      // 歌词（简化版）
      let lrcData = '';
      try {
        const lrcApi = getLyricApi(media, item.lyric_id);
        const lrcResp = await execApi(media, lrcApi);
        const lrcJson = JSON.parse(lrcResp);
        lrcData = (lrcJson.lyric || '') + '\n' + (lrcJson.tlyric || '');
      } catch (e) { /* ignore */ }

      result.push({
        name: item.name,
        url: url,
        song_id: item.id,
        cover: coverUrl,
        author: (item.artist || []).join(' / '),
        lrc_data: lrcData,
        version: '1.5.10',
      });
    }

    return new Response(JSON.stringify(result, null, 2), {
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Access-Control-Allow-Origin': '*' },
    });

  } catch (err) {
    return new Response(JSON.stringify({ error: err.message }), {
      status: 500,
      headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
    });
  }
}

function getGenericApi(media, type, id) {
  switch (type) {
    case 'song':
      if (media === 'netease') return { method: 'POST', url: 'http://music.163.com/api/v3/song/detail/', body: { c: `[{"id":${id},"v":0}]` }, encode: 'netease_AESCBC', format: 'songs' };
      if (media === 'tencent') return { method: 'GET', url: 'https://c.y.qq.com/v8/fcg-bin/fcg_play_single_song.fcg', body: { songmid: id, platform: 'yqq', format: 'json' }, format: 'data' };
      // 默认 netease
      return { method: 'POST', url: 'http://music.163.com/api/v3/song/detail/', body: { c: `[{"id":${id},"v":0}]` }, encode: 'netease_AESCBC', format: 'songs' };
    default:
      return { method: 'POST', url: 'http://music.163.com/api/v3/song/detail/', body: { c: `[{"id":${id},"v":0}]` }, encode: 'netease_AESCBC', format: 'songs' };
  }
}

// ====================== 主入口 ======================
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const pathname = url.pathname;

    // CORS 预检
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
          'Access-Control-Allow-Headers': 'Content-Type',
        },
      });
    }

    // API 路由
    if (pathname === '/api' || pathname.startsWith('/api?')) {
      return handleApiRequest(url, request);
    }

    // Get.php 备用 API
    if (pathname === '/get' || pathname.startsWith('/get?')) {
      return handleGetApi(url, request);
    }

    // 其他请求交给静态资源（Pages Assets）
    // 如果部署为纯 Worker，回退到 404
    return new Response('Not Found - Static files should be served by Cloudflare Pages', { status: 404 });
  },
};
