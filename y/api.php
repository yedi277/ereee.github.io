<?php
/**************************************************
 * MKOnlinePlayer v2.4
 * 后台音乐数据抓取模块
 * 编写：mengkun(https://mkblog.cn)
 * 时间：2018-3-11
 * 特别感谢 @metowolf 提供的 Meting.php
 *************************************************/

/************ ↓↓↓↓↓ 如果网易云音乐歌曲获取失效，请将你的 COOKIE 放到这儿 ↓↓↓↓↓ ***************/
$netease_cookie = 'NMTID=00OvB3cfmiiyB5mFEdasBzWzi1yHZUAAAGJn_B3yQ; _iuqxldmzr_=32; _ntes_nuid=e095090cd34b1ef4dd13faeb5db3d640; _ntes_nnid=e095090cd34b1ef4dd13faeb5db3d640,1690605484518; WEVNSM=1.0.0; WNMCID=brckwt.1690605486412.01.0; ntes_utid=tid._.hcDVdOcEL%2FBARkEVAQeF0sa%2Fu1CQDv8g._.0; sDeviceId=YD-1nL8V7rq37lFB1UQFEOBl9eru0GUGv9m; __snaker__id=Jhy6vAejgnnsFZHq; WM_NI=Ybv1b0ig1JqJrcyxt23jPDte0jV2goY2yvmFjlmrfVklioAVVkNtbRgwYT8XEg6zeOl1sBhJipCnNNj2r0w4pcCGixPB523d3I5HUP2Ex2DKP+FqiM2Utvowt0QNeSo/cnY=; WM_TID=PIrNiW5IBeBEBFBUBFOVkoLuvlCVYOtG; WM_NIKE=9ca17ae2e6ffcda170e2e6ee97e55c97a89c88fc5ba9ac8ab6d54e969f8b86d568fbbebb92ea448287abd6c72af0fea7c3b92a9bbebad2e13cabb9ffabf442b2bdb799f73af1b6a0a5f76ff4b69ad3e6659beeb797f04eaab4a393e55ea9b4a790c45e96b58295e15aa5a997b5bb5487b9fa9be845b0efbda3eb42abb08d8fcd46aaeebed1f640b4bd8da8ec74949efbd3b270f197fba9d85cf29bb8d3c74194f58a92ec47f28bbc97ee7db8f09fafe859adf1ad8fdc37e2a3; YD00000558929251:WM_NI=axMaIpwOHWnmrf9OnCJnIaARPdnzLBNN7aMfZw5APeAPmBGth/hpuKnjEK8Afwa/RERNXFnWYl1IqG7eNjw5JTpzIcDwCxTkpzmrhu8OaGp0X+Nbl3z5Vz1TErmpdgJZWVI=; YD00000558929251:WM_TID=M98Ms1WcdvxBRVRRBULFl8e+/kTBKJ+w; YD00000558929251:WM_NIKE=9ca17ae2e6ffcda170e2e6eea4ce3dedac8488e9799aac8eb6d85e969b9fadd561adbabe96fb6083beac93fb2af0fea7c3b92aa3a88382cc7cb59188b8ed67b2baf7b0ed498ab28782c45a94bca29bcf4a8e92f982d06e9aa9fba2d36f8390a3a3c680acf1a891f841aeb68bacbb4ba6a9afd0d14f929298a7ed5d9bb4ffaaf44df5bb828bf63f8a8cc09bcc6988a98d99d761b4a6a393eb7ffc91afa8f33c9cf58283ef39baeb9a9bb46087aea391e76d8a8699b7cc37e2a3; __remember_me=true; JSESSIONID-WYYY=cdmrKwd4cvRuOhKEF9srSp0ItZXnnNeOiGXOznBFh+hpyVj6+UJPPfXHSVdc8XiFgPeOev6K2o7F2m9b73a/fdccyAayaVXRiZsBaZIN4cE11xRlkKA\gwYiVUIKoA2A5Z/mlr5VljX7TC8y/dpHuortDKvJFSE1hV2eo9kdFpI7nRPC:1690610764753; gdxidpyhxdE=gibhWi9ujBB6wbI19vMSrzeyHImeXb53WAWc8wLCAIdI48Wb6YxKSkX/Hn0R+b3uIod2Tr3GeAXDzktmpyII853bek7mQVNt8H06NXzOvJS8UXMftK7n6KQ71ZMp3gm7/mBRE6PEitPYnS0KOTJNw3iUiU4Iickyy\7VpsU\EnmQ3c7W:1690610425789; NTES_YD_SESS=fdX5nocewMSgay1HrrrvZCPa3jD4zG8x65Z_nCgy8ZCIeypJekSXAhFJQfv.oqvWJnun9U7gMI6iMkSmwjRyhmj38xkQILsbyfSDn26Ew6ao4ntnuzJzuiuJ.BMqKoiFEJgDxLOXbIL8znMjsLCsE2jp0IvRM9517Gl3sirSHA61j.SnId4MGaWmKBvYnpzj_xPxqzvHOGidzmqQDu6IPM1Ug0lMR5n2kb8k8o9PYHtFR; S_INFO=1690609689|0|0&60##|19937275504; P_INFO=19937275504|1690609689|1|music|00&99|null&null&null#hun&430200#10#0|&0||19937275504; __csrf=d991742037be3d7f29b012eae66cb024; MUSIC_U=00B8AEA42A74454262620F8D87C72B34B7FDDB113A1CAD8EBCDD03FFA8D76EA546E4B65A5B06046EC9A2751DFD90485C637DAE9721DFC264D6935CC0C34E56726CE2CE151F3A91771FD764D11A6A697AA836290A0470F9C2992CBC932D788BFFDC2F3D3A3D75230DAD7D7A2DB4D3F4271B8DFDACFA886397EEE1AC15A05B7C6C6D50B708BA3B349B727CC1F62F3F17DC2D43825395710B4724A0E90615D2AF5E5777D305C75D2427EDFABAFFAC7DAAA0A7E10DE5C23DADDB166BFE2C06AE584EA93B2ABFBB4141A088A87245DCF5204CE6CB164B69455741DF81BEE4EDB2830DCB9B1347686630A5A7BA792130F28F2339661F20EBC2991741FC4EE847B79CEF7CDAF06B12C94BB92DD75C1FB0674105C2939882BEEA4C88C25D68B489AE5ADB0641117F49D57C128471EF481FD8A0A53405448E7A48BE4A5BA2B07570AE7D4AA38CE9DF0ED0DAA2A52E2524D8DB0C4CD6069A130DB665E96494F0F635833B2E5998D832203DCC069C4512BFCB36353829; ntes_kaola_ad=1';
/************ ↑↑↑↑↑ 如果网易云音乐歌曲获取失效，请将你的 COOKIE 放到这儿 ↑↑↑↑↑ ***************/
/**
* cookie 获取及使用方法见 
* https://github.com/mengkunsoft/MKOnlineMusicPlayer/wiki/%E7%BD%91%E6%98%93%E4%BA%91%E9%9F%B3%E4%B9%90%E9%97%AE%E9%A2%98
* 
* 更多相关问题可以查阅项目 wiki 
* https://github.com/mengkunsoft/MKOnlineMusicPlayer/wiki
* 
* 如果还有问题，可以提交 issues
* https://github.com/mengkunsoft/MKOnlineMusicPlayer/issues
**/


define('HTTPS', false);    // 如果您的网站启用了https，请将此项置为“true”，如果你的网站未启用 https，建议将此项设置为“false”
define('DEBUG', false);      // 是否开启调试模式，正常使用时请将此项置为“false”
define('CACHE_PATH', 'cache/');     // 文件缓存目录,请确保该目录存在且有读写权限。如无需缓存，可将此行注释掉

/*
 如果遇到程序不能正常运行，请开启调试模式，然后访问 http://你的网站/音乐播放器地址/api.php ，进入服务器运行环境检测。
 此外，开启调试模式后，程序将输出详细的运行错误信息，方便定位错误原因。
 
 因为调试模式下程序会输出服务器环境信息，为了您的服务器安全，正常使用时请务必关闭调试。
*/



/*****************************************************************************************************/
if(!defined('DEBUG') || DEBUG !== true) error_reporting(0); // 屏蔽服务器错误

require_once('plugns/Meting.php');

use Metowolf\Meting;

$source = getParam('source', 'netease');  // 歌曲源
$API = new Meting($source);

$API->format(true); // 启用格式化功能

if($source == 'kugou' || $source == 'baidu') {
    define('NO_HTTPS', true);        // 酷狗和百度音乐源暂不支持 https
} elseif(($source == 'netease') && $netease_cookie) {
    $API->cookie($netease_cookie);    // 解决网易云 Cookie 失效
}

// 没有缓存文件夹则创建
if(defined('CACHE_PATH') && !is_dir(CACHE_PATH)) createFolders(CACHE_PATH);

$types = getParam('types');
switch($types)   // 根据请求的 Api，执行相应操作
{
    case 'url':   // 获取歌曲链接
        $id = getParam('id');  // 歌曲ID
        
        $data = $API->url($id);
        
        echojson($data);
        break;
        
    case 'pic':   // 获取歌曲链接
        $id = getParam('id');  // 歌曲ID
        
        $data = $API->pic($id);
        
        echojson($data);
        break;
    
    case 'lyric':       // 获取歌词
        $id = getParam('id');  // 歌曲ID
        
        if(($source == 'netease') && defined('CACHE_PATH')) {
            $cache = CACHE_PATH.$source.'_'.$types.'_'.$id.'.json';
            
            if(file_exists($cache)) {   // 缓存存在，则读取缓存
                $data = file_get_contents($cache);
            } else {
                $data = $API->lyric($id);
                
                // 只缓存链接获取成功的歌曲
                if(json_decode($data)->lyric !== '') {
                    file_put_contents($cache, $data);
                }
            }
        } else {
            $data = $API->lyric($id);
        }
        
        echojson($data);
        break;
        
    case 'download':    // 下载歌曲(弃用)
        $fileurl = getParam('url');  // 链接
        
        header('location:$fileurl');
        exit();
        break;
    
    case 'userlist':    // 获取用户歌单列表
        $uid = getParam('uid');  // 用户ID
        
        $url= 'http://music.163.com/api/user/playlist/?offset=0&limit=1001&uid='.$uid;
        $data = file_get_contents($url);
        
        echojson($data);
        break;
        
    case 'playlist':    // 获取歌单中的歌曲
        $id = getParam('id');  // 歌单ID
        
        if(($source == 'netease') && defined('CACHE_PATH')) {
            $cache = CACHE_PATH.$source.'_'.$types.'_'.$id.'.json';
            
            if(file_exists($cache) && (date("Ymd", filemtime($cache)) == date("Ymd"))) {   // 缓存存在，则读取缓存
                $data = file_get_contents($cache);
            } else {
                $data = $API->format(false)->playlist($id);
                
                // 只缓存链接获取成功的歌曲
                if(isset(json_decode($data)->playlist->tracks)) {
                    file_put_contents($cache, $data);
                }
            }
        } else {
            $data = $API->format(false)->playlist($id);
        }
        
        echojson($data);
        break;
     
    case 'search':  // 搜索歌曲
        $s = getParam('name');  // 歌名
        $limit = getParam('count', 20);  // 每页显示数量
        $pages = getParam('pages', 1);  // 页码
        
        $data = $API->search($s, [
            'page' => $pages, 
            'limit' => $limit
        ]);
        
        echojson($data);
        break;
        
    default:
        echo '<!doctype html><html><head><meta charset="utf-8"><title>信息</title><style>* {font-family: microsoft yahei}</style></head><body> <h2>MKOnlinePlayer</h2><h3>Github: https://github.com/mengkunsoft/MKOnlineMusicPlayer</h3><br>';
        if(!defined('DEBUG') || DEBUG !== true) {   // 非调试模式
            echo '<p>Api 调试模式已关闭</p>';
        } else {
            echo '<p><font color="red">您已开启 Api 调试功能，正常使用时请在 api.php 中关闭该选项！</font></p><br>';
            
            echo '<p>PHP 版本：'.phpversion().' （本程序要求 PHP 5.4+）</p><br>';
            
            echo '<p>服务器函数检查</p>';
            echo '<p>curl_exec: '.checkfunc('curl_exec',true).' （用于获取音乐数据）</p>';
            echo '<p>file_get_contents: '.checkfunc('file_get_contents',true).' （用于获取音乐数据）</p>';
            echo '<p>json_decode: '.checkfunc('json_decode',true).' （用于后台数据格式化）</p>';
            echo '<p>hex2bin: '.checkfunc('hex2bin',true).' （用于数据解析）</p>';
            echo '<p>openssl_encrypt: '.checkfunc('openssl_encrypt',true).' （用于数据解析）</p>';
        }
        
        echo '</body></html>';
}

/**
 * 创建多层文件夹 
 * @param $dir 路径
 */
function createFolders($dir) {
    return is_dir($dir) or (createFolders(dirname($dir)) and mkdir($dir, 0755));
}

/**
 * 检测服务器函数支持情况
 * @param $f 函数名
 * @param $m 是否为必须函数
 * @return 
 */
function checkfunc($f,$m = false) {
	if (function_exists($f)) {
		return '<font color="green">可用</font>';
	} else {
		if ($m == false) {
			return '<font color="black">不支持</font>';
		} else {
			return '<font color="red">不支持</font>';
		}
	}
}

/**
 * 获取GET或POST过来的参数
 * @param $key 键值
 * @param $default 默认值
 * @return 获取到的内容（没有则为默认值）
 */
function getParam($key, $default='')
{
    return trim($key && is_string($key) ? (isset($_POST[$key]) ? $_POST[$key] : (isset($_GET[$key]) ? $_GET[$key] : $default)) : $default);
}

/**
 * 输出一个json或jsonp格式的内容
 * @param $data 数组内容
 */
function echojson($data)    //json和jsonp通用
{
    header('Content-type: application/json');
    $callback = getParam('callback');
    
    if(defined('HTTPS') && HTTPS === true && !defined('NO_HTTPS')) {    // 替换链接为 https
        $data = str_replace('http:\/\/', 'https:\/\/', $data);
        $data = str_replace('http://', 'https://', $data);
    }
    
    if($callback) //输出jsonp格式
    {
        die(htmlspecialchars($callback).'('.$data.')');
    } else {
        die($data);
    }
}