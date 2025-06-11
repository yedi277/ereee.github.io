/**************************************************
 * MKOnlinePlayer v2.32
 * 播放列表配置模块
 * 编写：mengkun(http://mkblog.cn)
 * 时间：2017-9-15
 *************************************************/
// 建议修改前先备份一下
// 获取 歌曲的网易云音乐ID 或 网易云歌单ID 的方法：
// 先在 js/player.js 中开启调试模式，然后按 F12 打开浏览器的控制台。播放歌曲或点开歌单即可看到相应信息

var musicList = [
    // 以下三个系统预留列表请勿更改，否则可能导致程序无法正常运行！
    // 预留列表：搜索结果
    {
        name: "搜索结果",   // 播放列表名字
        cover: " ",          // 播放列表封面
        creatorName: " ",        // 列表创建者名字
        creatorAvatar: " ",      // 列表创建者头像
        item: []
    },
    // 预留列表：正在播放
    {
        name: "正在播放",   // 播放列表名字
        cover: " ",          // 播放列表封面
        creatorName: " ",        // 列表创建者名字
        creatorAvatar: " ",      // 列表创建者头像
        item: []
    },
    // 预留列表：播放历史
    {
        name: "播放历史",   // 播放列表名字
        cover: "images/history.png",          // 播放列表封面
        creatorName: " ",        // 列表创建者名字
        creatorAvatar: " ",      // 列表创建者头像
        item: []
    },  
    // 以上三个系统预留列表请勿更改，否则可能导致程序无法正常运行！
    //*********************************************
    // 自定义列表开始，您可以自由添加您的自定义列表




    // 自定义列表教程开始！
    // 方式一：手动创建列表并添加歌曲信息
    // 温馨提示：各大音乐平台获取到的外链有效期均较短，因此 url 值应该设置为空，以让程序临时抓取
    {
        name: "本地列表",   // 播放列表名字
        cover: "https://p3.music.126.net/qg3MkYPvU8A22Aqg451-AQ==/109951165714848195.jpg?param=300y300", // 播放列表封面图像
        creatorName: " ",        // 列表创建者名字(暂时没用到，可空)
        creatorAvatar: " ",      // 列表创建者头像(暂时没用到，可空)
        item: [                 // 这里面放歌曲
			{
                id: "357462",  // 音乐ID
                name: "123木头人",  // 音乐名字
                artist: "黑Girl", // 艺术家名字
                album: "",    // 专辑名字
                source: "netease",     // 音乐来源
                url_id: "357462",  // 链接ID
                pic_id: "",  // 封面ID
                lyric_id: "357462",  // 歌词ID
                pic: "https://p3.music.126.net/qg3MkYPvU8A22Aqg451-AQ==/109951165714848195.jpg?param=300y300",    // 专辑图片
                url: "http://g.yedii.com/music/123木头人 -黑Girl.mp3"   // mp3链接（此项建议不填，除非你有该歌曲的比较稳定的外链）
            }, {
        id: "1217823",
        name: "B What U Wanna B",
        artist: "Darin",
        album: "",
        source: "",
        url_id: "1217823",
        pic_id: "109951165994365993",
        lyric_id: "1217823",
        pic: "",
        url: "http://g.yedii.com/music/B What U Wanna B-Darin.mp3"
    }, {
        id: "31384245",
        name: "Boom Clap",
        artist: "Charli XCX",
        album: "",
        source: "",
        url_id: "31384245",
        pic_id: "2895014116201169",
        lyric_id: "31384245",
        pic: "",
        url: "http://g.yedii.com/music/Boom Clap-Charli XCX.mp3"
    }, {
        id: "4164331",
        name: "Bye Bye Bye",
        artist: "Lovestoned",
        album: "",
        source: "",
        url_id: "4164331",
        pic_id: "1695446930044953",
        lyric_id: "4164331",
        pic: "",
        url: "http://g.yedii.com/music/Bye Bye Bye-Lovestoned.mp3"
    }, {
        id: "16432803",
        name: "Chancenlos",
        artist: "Annett Louisan",
        album: "",
        source: "netease",
        url_id: "16432803",
        pic_id: "694891348761475",
        lyric_id: "16432803",
        pic: "null",
        url: "http://g.yedii.com/music/Chancenlos-Annett Louisan.mp3"
    }, {
        id: "22494632",
        name: "Danke",
        artist: "LaFee",
        album: "",
        source: "",
        url_id: "22494632",
        pic_id: "109951168041060065",
        lyric_id: "22494632",
        pic: "",
        url: "http://g.yedii.com/music/Danke-LaFee.mp3"
    }, {
        id: "16432790",
        name: "Die DingeAnnett Louisan",
        artist: "Annett Louisan",
        album: "",
        source: "",
        url_id: "16432790",
        pic_id: "597034813889354",
        lyric_id: "16432790",
        pic: "",
        url: "http://g.yedii.com/music/Die Dinge-Annett Louisan.mp3"
    }, {
        id: "28256265",
        name: "Du fehlst mir so",
        artist: "Annett Louisan",
        album: "",
        source: "",
        url_id: "28256265",
        pic_id: "109951165969123610",
        lyric_id: "28256265",
        pic: "",
        url: "http://g.yedii.com/music/Du fehlst mir so-Annett Louisan.mp3"
    }, {
        id: "3880911",
        name: "Es Ist Ein Schnee Gefallen",
        artist: "Adaro",
        album: "",
        source: "",
        url_id: "3880911",
        pic_id: "1699844976549492",
        lyric_id: "3880911",
        pic: "",
        url: "http://g.yedii.com/music/Es Ist Ein Schnee Gefallen-Adaro.mp3"
    }, {
        id: "36990266",
        name: "Faded",
        artist: "Alan Walker",
        album: "",
        source: "",
        url_id: "36990266",
        pic_id: "109951165976856263",
        lyric_id: "36990266",
        pic: "",
        url: "http://g.yedii.com/music/Faded-Alan Walker.mp3"
    }, {
        id: "2700760",
        name: "Free To Be Me",
        artist: "Haroula Rose",
        album: "",
        source: "",
        url_id: "2700760",
        pic_id: "109951164854956267",
        lyric_id: "2700760",
        pic: "",
        url: "http://g.yedii.com/music/Free To Be Me-Haroula Rose.mp3"
    }, {
        id: "17635351",
        name: "Für immer",
        artist: "Eisblume",
        album: "",
        source: "",
        url_id: "17635351",
        pic_id: "755364488290776",
        lyric_id: "17635351",
        pic: "",
        url: "http://g.yedii.com/music/Für immer-Eisblume.mp3"
    }, {
        id: "2919622",
        name: "I Am You",
        artist: "Kim Taylor",
        album: "",
        source: "",
        url_id: "2919622",
        pic_id: "109951163073357482",
        lyric_id: "2919622",
        pic: "",
        url: "http://g.yedii.com/music/I Am You-Kim Taylor.mp3"
    }, {
        id: "3026583",
        name: "I Remember",
        artist: "MOCCA",
        album: "",
        source: "",
        url_id: "3026583",
        pic_id: "109951163436732617",
        lyric_id: "3026583",
        pic: "",
        url: "http://g.yedii.com/music/I Remember-MOCCA.mp3"
    }, {
        id: "26348081",
        name: "Morning",
        artist: "卫兰",
        album: "",
        source: "",
        url_id: "26348081",
        pic_id: "109951163402005652",
        lyric_id: "26348081",
        pic: "",
        url: "http://g.yedii.com/music/Morning-卫兰.mp3"
    }, {
        id: "22735051",
        name: "Oh!",
        artist: "少女时代",
        album: "",
        source: "",
        url_id: "22735051",
        pic_id: "18502581673986786",
        lyric_id: "22735051",
        pic: "",
        url: "http://g.yedii.com/music/Oh!-少女时代.mp3"
    }, {
        id: "18449193",
        name: "Say Hello",
        artist: "Rosie Thomas;Sufjan Stevens",
        album: "",
        source: "",
        url_id: "18449193",
        pic_id: "109951166683572040",
        lyric_id: "18449193",
        pic: "",
        url: "http://g.yedii.com/music/Say Hello-Rosie Thomas;Sufjan Stevens.mp3"
    }, {
        id: "20036333",
        name: "Schlaflied",
        artist: "Kate & Ben",
        album: "",
        source: "",
        url_id: "20036333",
        pic_id: "2538772349663439",
        lyric_id: "20036333",
        pic: "",
        url: "http://g.yedii.com/music/Schlaflied-Kate & Ben.mp3"
    }, {
        id: "468882985",
        name: "Shape of You",
        artist: "J.Fla",
        album: "Shape of You",
        source: "",
        url_id: "468882985",
        pic_id: "109951163032775841",
        lyric_id: "468882985",
        pic: "",
        url: "http://g.yedii.com/music/Shape of You-J.Fla.mp3"
    }, {
        id: "19292842",
        name: "Speak Now",
        artist: "Taylor Swift",
        album: "",
        source: "",
        url_id: "19292842",
        pic_id: "109951166119330991",
        lyric_id: "19292842",
        pic: "",
        url: "http://g.yedii.com/music/Speak Now-Taylor Swift.mp3"
    }, {
        id: "18836961",
        name: "Stay Here Forever",
        artist: "Jewel",
        album: "",
        source: "",
        url_id: "18836961",
        pic_id: "19027048718874686",
        lyric_id: "18836961",
        pic: "",
        url: "http://g.yedii.com/music/Stay Here Forever-Jewel.mp3"
    }, {
        id: "28593339",
        name: "Sunshine Girl",
        artist: "moumoon",
        album: "ICE CANDY",
        source: "",
        url_id: "28593339",
        pic_id: "18591642115850247",
        lyric_id: "28593339",
        pic: "",
        url: "http://g.yedii.com/music/Sunshine Girl-moumoon.mp3"
    }, {
        id: "2010944468",
        name: "Symphonie",
        artist: "Silbermond",
        album: "",
        source: "",
        url_id: "2010944468",
        pic_id: "109951168198554891",
        lyric_id: "2010944468",
        pic: "",
        url: "http://g.yedii.com/music/Symphonie-Silbermond.mp3"
    }, {
        id: "22294416",
        name: "The Girl With the Northern Soul CollectionClub 8",
        artist: "",
        album: "",
        source: "",
        url_id: "22294416",
        pic_id: "109951167580317195",
        lyric_id: "22294416",
        pic: "",
        url: "http://g.yedii.com/music/The Girl With the Northern Soul Collection-Club 8.mp3"
    }, {
        id: "32272264",
        name: "Wie geht glücklich",
        artist: "Sarah Connor",
        album: "",
        source: "",
        url_id: "32272264",
        pic_id: "2910407280165696",
        lyric_id: "32272264",
        pic: "",
        url: "http://g.yedii.com/music/Wie geht glücklich-Sarah Connor.mp3"
    }, {
        id: "3880988",
        name: "Wigen Wagen",
        artist: "Adaro",
        album: "Minnenspiel",
        source: "",
        url_id: "3880988",
        pic_id: "109951166239927364",
        lyric_id: "3880988",
        pic: "",
        url: "http://g.yedii.com/music/Wigen Wagen-Adaro.mp3"
    }, {
        id: "560399448",
        name: "极乐净土",
        artist: "",
        album: "",
        source: "",
        url_id: "560399448",
        pic_id: "109951163295861375",
        lyric_id: "560399448",
        pic: "",
        url: "http://g.yedii.com/music/《极乐净土》官方中文版_哔哩哔哩 (゜-゜)つロ 干杯~-bilibili_2.mp3"
    }, {
        id: "547901127",
        name: "阿刁",
        artist: "【祖娅纳惜·FRE】",
        album: "",
        source: "",
        url_id: "547901127",
        pic_id: "109951163137882968",
        lyric_id: "547901127",
        pic: "",
        url: "http://g.yedii.com/music/【祖娅纳惜·FRE】阿刁_哔哩哔哩 (゜-゜)つロ 干杯~-bilibili.mp3"
    }, {
        id: "229336",
        name: "一个像夏天一个像秋天",
        artist: "范玮琪",
        album: "",
        source: "",
        url_id: "229336",
        pic_id: "109951168750873662",
        lyric_id: "229336",
        pic: "",
        url: "http://g.yedii.com/music/一个像夏天一个像秋天-范玮琪.mp3"
    }, {
        id: "326990",
        name: "亲爱的,那不是爱情",
        artist: "张韶涵",
        album: "",
        source: "",
        url_id: "326990",
        pic_id: "109951167379860467",
        lyric_id: "326990",
        pic: "",
        url: "http://g.yedii.com/music/亲爱的,那不是爱情-张韶涵.mp3"
    }, {
        id: "394537",
        name: "低着头",
        artist: "自然卷",
        album: "C'est La Vie 2.5 资源回收",
        source: "",
        url_id: "394537",
        pic_id: "638816255750145",
        lyric_id: "394537",
        pic: "",
        url: "http://g.yedii.com/music/低着头-自然卷.mp3"
    }, {
        id: "368813",
        name: "初恋粉色系 ",
        artist: "南拳妈妈",
        album: "",
        source: "",
        url_id: "368813",
        pic_id: "109951163244870797",
        lyric_id: "368813",
        pic: "",
        url: "http://g.yedii.com/music/初恋粉色系 -南拳妈妈.mp3"
    }, {
        id: "254485",
        name: "勇气",
        artist: "梁静茹",
        album: "",
        source: "",
        url_id: "254485",
        pic_id: "109951163240604120",
        lyric_id: "254485",
        pic: "",
        url: "http://g.yedii.com/music/勇气-梁静茹.mp3"
    }, {
        id: "1409152181",
        name: "卓别林",
        artist: "龚芝怡",
        album: "",
        source: "",
        url_id: "1409152181",
        pic_id: "109951164544592419",
        lyric_id: "1409152181",
        pic: "",
        url: "http://g.yedii.com/music/卓别林-龚芝怡.mp3"
    }, {
        id: "92305",
        name: "单车恋人",
        artist: "后弦",
        album: "",
        source: "",
        url_id: "92305",
        pic_id: "666304046444415",
        lyric_id: "92305",
        pic: "",
        url: "http://g.yedii.com/music/单车恋人-后弦.mp3"
    }, {
        id: "326719",
        name: "喜欢",
        artist: "张悬",
        album: "",
        source: "",
        url_id: "326719",
        pic_id: "109951168632695603",
        lyric_id: "326719",
        pic: "",
        url: "http://g.yedii.com/music/喜欢-张悬.mp3"
    }, {
        id: "28949444",
        name: "喜欢你G.E.M.",
        artist: "邓紫棋",
        album: "",
        source: "",
        url_id: "28949444",
        pic_id: "8896148580676276",
        lyric_id: "28949444",
        pic: "",
        url: "http://g.yedii.com/music/喜欢你-G.E.M.邓紫棋.mp3"
    }, {
        id: "394653",
        name: "坐在巷口的那对男女",
        artist: "自然卷",
        album: "",
        source: "",
        url_id: "394653",
        pic_id: "78065325584894",
        lyric_id: "394653",
        pic: "",
        url: "http://g.yedii.com/music/坐在巷口的那对男女-自然卷.mp3"
    }, {
        id: "326738",
        name: "宝贝",
        artist: "张悬",
        album: "",
        source: "",
        url_id: "326738",
        pic_id: "109951167872578215",
        lyric_id: "326738",
        pic: "",
        url: "http://g.yedii.com/music/宝贝-张悬.mp3"
    }, {
        id: "421423806",
        name: "小半",
        artist: "陈粒",
        album: "",
        source: "",
        url_id: "421423806",
        pic_id: "1371091013186741",
        lyric_id: "421423806",
        pic: "",
        url: "http://g.yedii.com/music/小半-陈粒.mp3"
    }, {
        id: "254146",
        name: "小手拉大手",
        artist: "梁静茹",
        album: "",
        source: "",
        url_id: "254146",
        pic_id: "109951168144462962",
        lyric_id: "254146",
        pic: "",
        url: "http://g.yedii.com/music/小手拉大手-梁静茹.mp3"
    }, {
        id: "1381755293",
        name: "山楂树之恋",
        artist: "程佳佳",
        album: "",
        source: "",
        url_id: "1381755293",
        pic_id: "109951164260611202",
        lyric_id: "1381755293",
        pic: "",
        url: "http://g.yedii.com/music/山楂树之恋-程佳佳.mp3"
    }, {
        id: "553543175",
        name: "平凡之路",
        artist: "朴树",
        album: "",
        source: "",
        url_id: "553543175",
        pic_id: "109951163252275728",
        lyric_id: "553543175",
        pic: "",
        url: "http://g.yedii.com/music/平凡之路-朴树.mp3"
    }, {
        id: "461011",
        name: "恋愛サーキュレーション",
        artist: "花澤香菜",
        album: "",
        source: "",
        url_id: "461011",
        pic_id: "109951166198077552",
        lyric_id: "461011",
        pic: "",
        url: "http://g.yedii.com/music/恋愛サーキュレーション-花澤香菜.mp3"
    }, {
        id: "254059",
        name: "情歌",
        artist: "梁静茹",
        album: "",
        source: "",
        url_id: "254059",
        pic_id: "109951168163257789",
        lyric_id: "254059",
        pic: "",
        url: "http://g.yedii.com/music/情歌-梁静茹.mp3"
    }, {
        id: "409654891",
        name: "故梦",
        artist: "双笙",
        album: "",
        source: "",
        url_id: "409654891",
        pic_id: "109951168638984216",
        lyric_id: "409654891",
        pic: "",
        url: "http://g.yedii.com/music/故梦-双笙.mp3"
    }, {
        id: "165340",
        name: "有点甜",
        artist: "汪苏泷;By2",
        album: "",
        source: "",
        url_id: "165340",
        pic_id: "109951164176658680",
        lyric_id: "165340",
        pic: "",
        url: "http://g.yedii.com/music/有点甜-汪苏泷;By2.mp3"
    }, {
        id: "327736",
        name: "梁山伯与茱丽叶",
        artist: "卓文萱;曹格",
        album: "",
        source: "",
        url_id: "327736",
        pic_id: "109951163423040944",
        lyric_id: "327736",
        pic: "",
        url: "http://g.yedii.com/music/梁山伯与茱丽叶-卓文萱;曹格.mp3"
    }, {
        id: "1851295915",
        name: "爱久见人心",
        artist: "梁静茹",
        album: "",
        source: "",
        url_id: "1851295915",
        pic_id: "109951166753798796",
        lyric_id: "1851295915",
        pic: "",
        url: "http://g.yedii.com/music/爱久见人心-梁静茹.mp3"
    }, {
        id: "233926",
        name: "狠狠哭",
        artist: "郭采洁",
        album: "",
        source: "",
        url_id: "233926",
        pic_id: "109951164081870241",
        lyric_id: "233926",
        pic: "",
        url: "http://g.yedii.com/music/狠狠哭-郭采洁.mp3"
    }, {
        id: "413829859",
        name: "童话镇",
        artist: "陈一发儿",
        album: "",
        source: "",
        url_id: "413829859",
        pic_id: "18345351510075909",
        lyric_id: "413829859",
        pic: "",
        url: "http://g.yedii.com/music/童话镇-陈一发儿.mp3"
    }, {
        id: "2054719988",
        name: "说好了不见面",
        artist: "小贱（谭冰尧）",
        album: "",
        source: "",
        url_id: "2054719988",
        pic_id: "109951168489987234",
        lyric_id: "2054719988",
        pic: "",
        url: "http://g.yedii.com/music/说好了不见面-小贱（谭冰尧）.mp3"
    }, {
        id: "355992",
        name: "追梦赤子心",
        artist: "Gala",
        album: "",
        source: "",
        url_id: "355992",
        pic_id: "19061133579343591",
        lyric_id: "355992",
        pic: "",
        url: "http://g.yedii.com/music/追梦赤子心-Gala.mp3"
    }, {
        id: "454828887",
        name: "遇见",
        artist: "孙燕姿",
        album: "",
        source: "",
        url_id: "454828887",
        pic_id: "17687843556430013",
        lyric_id: "454828887",
        pic: "",
        url: "http://g.yedii.com/music/遇见-孙燕姿.mp3"
    }, {
        id: "327235",
        name: "遗失的美好",
        artist: "张韶涵",
        album: "",
        source: "",
        url_id: "327235",
        pic_id: "109951167440837785",
        lyric_id: "327235",
        pic: "",
        url: "http://g.yedii.com/music/遗失的美好-张韶涵.mp3"
    }, {
        id: "2a24dea6c74884195fe5b9732fd95ca8",
        name: "小幸运",
        artist: "金玟岐",
        album: "金玟岐翻唱作品集",
        source: "kugou", // 酷狗 
		url_id:"2a24dea6c74884195fe5b9732fd95ca8", 
		pic_id:"2a24dea6c74884195fe5b9732fd95ca8", 
		lyric_id:"2a24dea6c74884195fe5b9732fd95ca8", 
		pic:"http://singerimg.kugou.com/uploadpic/softhead/400/20161226/20161226105135733.jpg", 
		url:"" // 酷狗的外链有效期较短，插入时 url[必须]设置空值，播放时再临时抓取
    }]
    // 列表中最后一首歌大括号后面不要加逗号]
    }, 
	{
        name: "老歌", // 播放列表名字 
		cover:"https://p3.music.126.net/0543F-ln2Apdiopez_jbsA==/109951163244853571.jpg?param=300y300", // 播放列表封面图像 
		creatorName:" ", // 列表创建者名字(暂时没用到，可空) 
		creatorAvatar:" ", // 列表创建者头像(暂时没用到，可空) 
		item:[ 
	{
        id: "150361",
        name: "三国恋",
        artist: "Tank",
        album: "Fighting!生存之道",
        source: "netease",
        url_id: "150361",
        pic_id: "109951163244853571",
        lyric_id: "150361",
        pic: "https://p3.music.126.net/0543F-ln2Apdiopez_jbsA==/109951163244853571.jpg?param=300y300",
        url: " /老歌/三国恋 - Tank.mp3"
    }, {
        id: "69827",
        name: "太多",
        artist: "陈冠蒲",
        album: "就让你走",
        source: "netease",
        url_id: "69827",
        pic_id: "109951165293600860",
        lyric_id: "69827",
        pic: "null",
        url: " /老歌/太多 - 陈冠蒲.mp3"
    }, {
        id: "239942",
        name: "不要用我的爱来伤害我",
        artist: "韩晶",
        album: "不要用我的爱来伤害我",
        source: "netease",
        url_id: "239942",
        pic_id: "60473139533535",
        lyric_id: "239942",
        pic: "null",
        url: " /老歌/不要用我的爱来伤害我 - 韩晶.mp3"
    }, {
        id: "2061825547",
        name: "一直很安静（FarAty Bootleg）",
        artist: "FarAty",
        album: "阿桑",
        source: "netease",
        url_id: "2061825547",
        pic_id: "109951168720979969",
        lyric_id: "2061825547",
        pic: "null",
        url: " /老歌/一直很安静 - 阿桑.mp3"
    }, {
        id: "239188",
        name: "没那么简单",
        artist: "黄小琥",
        album: "简单/不简单",
        source: "netease",
        url_id: "239188",
        pic_id: "109951163695427333",
        lyric_id: "239188",
        pic: "null",
        url: " /老歌/没那么简单 - 黄小琥.mp3"
    }, {
        id: "178142",
        name: "擦肩而过",
        artist: "宇桐非",
        album: "为爱而声",
        source: "netease",
        url_id: "178142",
        pic_id: "109951164447827758",
        lyric_id: "178142",
        pic: "null",
        url: " /老歌/擦肩而过 - 宇桐非.mp3"
    }, {
        id: "176430",
        name: "爱上你是一个错",
        artist: "杨培安",
        album: "午夜两点半的我",
        source: "netease",
        url_id: "176430",
        pic_id: "109951168602506322",
        lyric_id: "176430",
        pic: "https://p3.music.126.net/_mMnOkzFFCdHzegUWrVwyQ==/109951168602506322.jpg?param=300y300",
        url: " /老歌/爱上你是一个错 - 杨培安.mp3"
    }, {
        id: "307081",
        name: "表白",
        artist: "萧亚轩",
        album: "1087",
        source: "netease",
        url_id: "307081",
        pic_id: "109951165623500611",
        lyric_id: "307081",
        pic: "null",
        url: " /老歌/表白 - 萧亚轩.mp3"
    }, {
        id: "85621",
        name: "第一次",
        artist: "光良",
        album: "第1次个人创作专辑",
        source: "netease",
        url_id: "85621",
        pic_id: "109951163287267754",
        lyric_id: "85621",
        pic: "https://p3.music.126.net/NlfftxU92TkoloqyecgwPw==/109951163287267754.jpg?param=300y300",
        url: "/老歌/第一次 - 光良.mp3"
    }, {
        id: "66525",
        name: "有没有人告诉你",
        artist: "陈楚生",
        album: "原来我一直都不孤单",
        source: "netease",
        url_id: "66525",
        pic_id: "53876069773370",
        lyric_id: "66525",
        pic: "null",
        url: " /老歌/有没有人告诉你 - 陈楚生.mp3"
    }, {
        id: "1476410303",
        name: "预谋",
        artist: "王琬茜",
        album: "一生一世一对一",
        source: "netease",
        url_id: "1476410303",
        pic_id: "109951165293167945",
        lyric_id: "1476410303",
        pic: "null",
        url: " /老歌/预谋 - 许佳慧.mp3"
    }, {
        id: "369173",
        name: "外滩十八号",
        artist: "男才女貌",
        album: "男才女貌",
        source: "netease",
        url_id: "369173",
        pic_id: "54975581401145",
        lyric_id: "369173",
        pic: "null",
        url: " /老歌/外滩十八号 - 袁成杰、戚薇.mp3"
    }, {
        id: "8ee2d830c68e9081c827ab3eb4cf952f",
        name: "老人与海",
        artist: "海鸣威",
        album: "Dance Dance Dance",
        source: "kugou",
        url_id: "8ee2d830c68e9081c827ab3eb4cf952f",
        pic_id: "8ee2d830c68e9081c827ab3eb4cf952f",
        lyric_id: "8ee2d830c68e9081c827ab3eb4cf952f",
        pic: "null",
        url: " /老歌/老人与海 - 海鸣威、吴琼.mp3"
    }, {
        id: "259a8c30da12364c588ca148e47ac135",
        name: "太早",
        artist: "刘允乐",
        album: "允乐",
        source: "kugou",
        url_id: "259a8c30da12364c588ca148e47ac135",
        pic_id: "259a8c30da12364c588ca148e47ac135",
        lyric_id: "259a8c30da12364c588ca148e47ac135",
        pic: "null",
        url: " /老歌/太早 - 刘允乐.mp3"
    }, {
        id: "5257437",
        name: "当你孤单你会想起谁",
        artist: "张栋梁",
        album: "Forever Love 34首动人国语精选情歌",
        source: "netease",
        url_id: "5257437",
        pic_id: "71468255823115",
        lyric_id: "5257437",
        pic: "null",
        url: " /老歌/当你孤单你会想起谁 - 张栋梁.mp3"
    }, {
        id: "3e2057a44e12b065c19073a9ce30b2c9",
        name: "被伤过的心还可以爱谁",
        artist: "六哲",
        album: "被伤过的心还可以爱谁",
        source: "kugou",
        url_id: "3e2057a44e12b065c19073a9ce30b2c9",
        pic_id: "3e2057a44e12b065c19073a9ce30b2c9",
        lyric_id: "3e2057a44e12b065c19073a9ce30b2c9",
        pic: "null",
        url: " /老歌/被伤过的心还可以爱谁 - 六哲.mp3"
    }, {
        id: "dfd5b81295325e474d0d3c979e172127",
        name: "生日礼物",
        artist: "江涛",
        album: "等到花没开",
        source: "kugou",
        url_id: "dfd5b81295325e474d0d3c979e172127",
        pic_id: "dfd5b81295325e474d0d3c979e172127",
        lyric_id: "dfd5b81295325e474d0d3c979e172127",
        pic: "null",
        url: " /老歌/生日礼物 - 江涛.mp3"
    }, {
        id: "20859cd14a9cbf023f4f100a714b2bd0",
        name: "花香",
        artist: "许绍洋",
        album: "薰衣草 电视剧原声带",
        source: "kugou",
        url_id: "20859cd14a9cbf023f4f100a714b2bd0",
        pic_id: "20859cd14a9cbf023f4f100a714b2bd0",
        lyric_id: "20859cd14a9cbf023f4f100a714b2bd0",
        pic: "null",
        url: " /老歌/花香 - 许绍洋.mp3"
    }, {
        id: "a3d1859a44a77135960be8c0a32dc624",
        name: "蝴蝶泉边",
        artist: "黄雅莉",
        album: "崽崽",
        source: "kugou",
        url_id: "a3d1859a44a77135960be8c0a32dc624",
        pic_id: "a3d1859a44a77135960be8c0a32dc624",
        lyric_id: "a3d1859a44a77135960be8c0a32dc624",
        pic: "null",
        url: " /老歌/蝴蝶泉边(Live) - 黄雅莉.mp3"
    }, {
        id: "ba4bf6853219717be2e1cbf3388aef86",
        name: "十一年",
        artist: "邱永传",
        album: "十一年",
        source: "kugou",
        url_id: "ba4bf6853219717be2e1cbf3388aef86",
        pic_id: "ba4bf6853219717be2e1cbf3388aef86",
        lyric_id: "ba4bf6853219717be2e1cbf3388aef86",
        pic: "null",
        url: " /老歌/十一年 - 邱永传.mp3"
    }, {
        id: "1472877668",
        name: "爱死了昨天 (live)",
        artist: "李慧珍",
        album: "流淌的歌声 第二季  第9期",
        source: "netease",
        url_id: "1472877668",
        pic_id: "109951165256207165",
        lyric_id: "1472877668",
        pic: "null",
        url: " /老歌/爱死了昨天 - 李慧珍.mp3"
    }, {
        id: "301464",
        name: "飞舞",
        artist: "王冰洋",
        album: "冰雪飞洋",
        source: "netease",
        url_id: "301464",
        pic_id: "31885837222351",
        lyric_id: "301464",
        pic: "null",
        url: " /老歌/飞舞 - 王冰洋.mp3"
    }, {
        id: "135362",
        name: "该死的温柔",
        artist: "马天宇",
        album: "自言自宇",
        source: "netease",
        url_id: "135362",
        pic_id: "109951165698169768",
        lyric_id: "135362",
        pic: "null",
        url: " /老歌/该死的温柔 - 马天宇.mp3"
    }, {
        id: "66525",
        name: "有没有人告诉你",
        artist: "陈楚生",
        album: "原来我一直都不孤单",
        source: "netease",
        url_id: "66525",
        pic_id: "53876069773370",
        lyric_id: "66525",
        pic: "https://p3.music.126.net/cuzFVNWVo3JwhWBITdSlfw==/53876069773370.jpg?param=300y300",
        url: "/老歌/有没有人告诉你 - 陈楚生.mp3"
    }, {
        id: "138793",
        name: "孤单北半球",
        artist: "欧得洋",
        album: "北半球有欧得洋",
        source: "netease",
        url_id: "138793",
        pic_id: "109951165422696727",
        lyric_id: "138793",
        pic: "null",
        url: " /老歌/孤单北半球 - 欧得洋.mp3"
    }, {
        id: "92305",
        name: "单车恋人",
        artist: "后弦",
        album: "9公主",
        source: "netease",
        url_id: "92305",
        pic_id: "666304046444415",
        lyric_id: "92305",
        pic: "null",
        url: "/老歌/单车恋人 - 后弦.mp3"
    }, {
        id: "2058621399",
        name: "别说我的眼泪你无所谓",
        artist: "东来东往",
        album: "别说我的眼泪你无所谓",
        source: "netease",
        url_id: "2058621399",
        pic_id: "109951168698042092",
        lyric_id: "2058621399",
        pic: "null",
        url: " /老歌/别说我的眼泪你无所谓 - 东来东往.mp3"
    }, {
        id: "360832",
        name: "杀破狼(Through The Fire Remix)",
        artist: "JS",
        album: "Somewhere",
        source: "netease",
        url_id: "360832",
        pic_id: "109951166117573535",
        lyric_id: "360832",
        pic: "null",
        url: "/老歌/杀破狼 - JS.mp3"
    }, {
        id: "c6cca2a224d7f6d75ed7ac5e1cdb22ec",
        name: "那么骄傲 (重遇版)",
        artist: "金海心",
        album: "那么骄傲 (重遇版)",
        source: "kugou",
        url_id: "c6cca2a224d7f6d75ed7ac5e1cdb22ec",
        pic_id: "c6cca2a224d7f6d75ed7ac5e1cdb22ec",
        lyric_id: "c6cca2a224d7f6d75ed7ac5e1cdb22ec",
        pic: "null",
        url: " /老歌/那么骄傲(重遇版) - 金海心.mp3"
    }, {
        id: "33431ba8c2fb438ed064ae5deeb2985f",
        name: "夏天的风",
        artist: "温岚",
        album: "温式效应",
        source: "kugou",
        url_id: "33431ba8c2fb438ed064ae5deeb2985f",
        pic_id: "33431ba8c2fb438ed064ae5deeb2985f",
        lyric_id: "33431ba8c2fb438ed064ae5deeb2985f",
        pic: "null",
        url: " /老歌/夏天的风 - 温岚.mp3"
    }, {
        id: "16df15ce32dee918c64cc2a98327f4b9",
        name: "想太多",
        artist: "李玖哲",
        album: "想太多",
        source: "kugou",
        url_id: "16df15ce32dee918c64cc2a98327f4b9",
        pic_id: "16df15ce32dee918c64cc2a98327f4b9",
        lyric_id: "16df15ce32dee918c64cc2a98327f4b9",
        pic: "null",
        url: "/老歌/想太多 - 李玖哲.mp3"
    }, {
        id: "712eb54370ad2067081c72b5f74b4b2e",
        name: "死心塌地",
        artist: "夹子道",
        album: "死心塌地",
        source: "kugou",
        url_id: "712eb54370ad2067081c72b5f74b4b2e",
        pic_id: "712eb54370ad2067081c72b5f74b4b2e",
        lyric_id: "712eb54370ad2067081c72b5f74b4b2e",
        pic: "null",
        url: " /老歌/死心塌地 - 夹子道.mp3"
    }, {
        id: "8ace2d4510e26fbdc4736055c5ae4b03",
        name: "有一种爱叫做放手",
        artist: "阿木",
        album: "有一种爱叫做放手",
        source: "kugou",
        url_id: "8ace2d4510e26fbdc4736055c5ae4b03",
        pic_id: "8ace2d4510e26fbdc4736055c5ae4b03",
        lyric_id: "8ace2d4510e26fbdc4736055c5ae4b03",
        pic: "null",
        url: " /老歌/有一种爱叫做放手 - 阿木.mp3"
    }, {
        id: "7b76de855eb7d5af18abc31e13cad93b",
        name: "最后一次的温柔",
        artist: "兰雨",
        album: "最后一次的温柔",
        source: "kugou",
        url_id: "7b76de855eb7d5af18abc31e13cad93b",
        pic_id: "7b76de855eb7d5af18abc31e13cad93b",
        lyric_id: "7b76de855eb7d5af18abc31e13cad93b",
        pic: "null",
        url: " /老歌/最后一次的温柔 - 兰雨.mp3"
    }, {
        id: "66c760662799f3ab7a59d53cb7631cf0",
        name: "下辈子如果我还记得你",
        artist: "马郁",
        album: "恋人絮语",
        source: "kugou",
        url_id: "66c760662799f3ab7a59d53cb7631cf0",
        pic_id: "66c760662799f3ab7a59d53cb7631cf0",
        lyric_id: "66c760662799f3ab7a59d53cb7631cf0",
        pic: "null",
        url: " /老歌/下辈子如果我还记得你 - 马郁.mp3"
    }, {
        id: "d1c38d4557f5842a44492c1992c3a79b",
        name: "天使的翅膀",
        artist: "安琥",
        album: "天使的翅膀",
        source: "kugou",
        url_id: "d1c38d4557f5842a44492c1992c3a79b",
        pic_id: "d1c38d4557f5842a44492c1992c3a79b",
        lyric_id: "d1c38d4557f5842a44492c1992c3a79b",
        pic: "null",
        url: " /老歌/天使的翅膀 - 安琥.mp3"
    }, {
        id: "a49e9cf2a435138df8b4df55c149fc44",
        name: "分手在那个秋天",
        artist: "浩瀚",
        album: "很想问你是否爱过我",
        source: "kugou",
        url_id: "a49e9cf2a435138df8b4df55c149fc44",
        pic_id: "a49e9cf2a435138df8b4df55c149fc44",
        lyric_id: "a49e9cf2a435138df8b4df55c149fc44",
        pic: "null",
        url: " /老歌/分手在那个秋天 - 浩瀚.mp3"
    }, {
        id: "1d687296864d97f63a3729dff785295d",
        name: "心痛2009",
        artist: "欢子",
        album: "其实很寂寞",
        source: "kugou",
        url_id: "1d687296864d97f63a3729dff785295d",
        pic_id: "1d687296864d97f63a3729dff785295d",
        lyric_id: "1d687296864d97f63a3729dff785295d",
        pic: "null",
        url: " /老歌/心痛2009 - 欢子.mp3"
    }, {
        id: "c60817bf6672fc113764c9c08d971c4d",
        name: "等一分钟",
        artist: "徐誉滕",
        album: "滕.爱",
        source: "kugou",
        url_id: "c60817bf6672fc113764c9c08d971c4d",
        pic_id: "c60817bf6672fc113764c9c08d971c4d",
        lyric_id: "c60817bf6672fc113764c9c08d971c4d",
        pic: "null",
        url: " /老歌/等一分钟 - 徐誉滕.mp3"
    }, {
        id: "1383381667",
        name: "犯错",
        artist: "顾峰",
        album: "犯错",
        source: "netease",
        url_id: "1383381667",
        pic_id: "109951164281883574",
        lyric_id: "1383381667",
        pic: "null",
        url: " /老歌/犯错 - 顾峰、斯琴高丽.mp3"
    }, {
        id: "5839b999eadfebe5fef286a00a3ee3d7",
        name: "求佛",
        artist: "誓言",
        album: "我的誓言",
        source: "kugou",
        url_id: "5839b999eadfebe5fef286a00a3ee3d7",
        pic_id: "5839b999eadfebe5fef286a00a3ee3d7",
        lyric_id: "5839b999eadfebe5fef286a00a3ee3d7",
        pic: "null",
        url: " /老歌/求佛 - 誓言.mp3"
    }, {
        id: "506fd1f0fce8d0c0ee11ee272c1d9636",
        name: "秋天不回来",
        artist: "王强",
        album: "我们的主打歌",
        source: "kugou",
        url_id: "506fd1f0fce8d0c0ee11ee272c1d9636",
        pic_id: "506fd1f0fce8d0c0ee11ee272c1d9636",
        lyric_id: "506fd1f0fce8d0c0ee11ee272c1d9636",
        pic: "null",
        url: " /老歌/秋天不回来 - 王强.mp3"
    }, {
        id: "fc2fbae20f74c10e3326e2f5dc823678",
        name: "你到底爱谁",
        artist: "刘嘉亮",
        album: "你到底爱谁（台湾版）",
        source: "kugou",
        url_id: "fc2fbae20f74c10e3326e2f5dc823678",
        pic_id: "fc2fbae20f74c10e3326e2f5dc823678",
        lyric_id: "fc2fbae20f74c10e3326e2f5dc823678",
        pic: "null",
        url: " /老歌/你到底爱谁 - 刘嘉亮.mp3"
    }, {
        id: "a47947fdf6aaac2f86577362ab3c38f1",
        name: "今生最爱",
        artist: "王程明",
        album: "龙乐文化2月单曲",
        source: "kugou",
        url_id: "a47947fdf6aaac2f86577362ab3c38f1",
        pic_id: "a47947fdf6aaac2f86577362ab3c38f1",
        lyric_id: "a47947fdf6aaac2f86577362ab3c38f1",
        pic: "null",
        url: " /老歌/今生最爱 - 王程明.mp3"
    }, {
        id: "780a8a5b22c5dcc484d7b8defc9d2f71",
        name: "做你的爱人",
        artist: "饶天亮",
        album: "做你的爱人",
        source: "kugou",
        url_id: "780a8a5b22c5dcc484d7b8defc9d2f71",
        pic_id: "780a8a5b22c5dcc484d7b8defc9d2f71",
        lyric_id: "780a8a5b22c5dcc484d7b8defc9d2f71",
        pic: "null",
        url: " /老歌/做你的爱人 - 饶天亮.mp3"
    }, {
        id: "7944fc9a656c8bb2078d323300f701ac",
        name: "回来我的爱",
        artist: "阳一",
        album: "回来我的爱",
        source: "kugou",
        url_id: "7944fc9a656c8bb2078d323300f701ac",
        pic_id: "7944fc9a656c8bb2078d323300f701ac",
        lyric_id: "7944fc9a656c8bb2078d323300f701ac",
        pic: "null",
        url: " /老歌/回来我的爱 - 阳一.mp3"
    }, {
        id: "50fecc8d5e39b12992cf56520de4aae2",
        name: "一个人的寂寞两个人的错",
        artist: "贺一航",
        album: "情伤",
        source: "kugou",
        url_id: "50fecc8d5e39b12992cf56520de4aae2",
        pic_id: "50fecc8d5e39b12992cf56520de4aae2",
        lyric_id: "50fecc8d5e39b12992cf56520de4aae2",
        pic: "null",
        url: " /老歌/一个人的寂寞两个人的错 - 贺一航.mp3"
    }, {
        id: "61bb8560d7fb979eb70ec5050990db95",
        name: "丁香花",
        artist: "唐磊",
        album: "丁香花",
        source: "kugou",
        url_id: "61bb8560d7fb979eb70ec5050990db95",
        pic_id: "61bb8560d7fb979eb70ec5050990db95",
        lyric_id: "61bb8560d7fb979eb70ec5050990db95",
        pic: "null",
        url: " /老歌/丁香花 - 唐磊.mp3"
    }, {
        id: "2a38df2e6fd85ccbb84c006c16ec8adc",
        name: "那一夜",
        artist: "谢军",
        album: "那一夜",
        source: "kugou",
        url_id: "2a38df2e6fd85ccbb84c006c16ec8adc",
        pic_id: "2a38df2e6fd85ccbb84c006c16ec8adc",
        lyric_id: "2a38df2e6fd85ccbb84c006c16ec8adc",
        pic: "null",
        url: " /老歌/那一夜 - 谢军.mp3"
    }, {
        id: "299470",
        name: "我不是黄蓉",
        artist: "王蓉",
        album: "我不是黄蓉",
        source: "netease",
        url_id: "299470",
        pic_id: "109951165625508031",
        lyric_id: "299470",
        pic: "null",
        url: " /老歌/我不是黄蓉 - 王蓉.mp3"
    }, {
        id: "cbe20c359509d697bf1a2f375553d5ce",
        name: "爱情错觉",
        artist: "王娅",
        album: "爱情错觉",
        source: "kugou",
        url_id: "cbe20c359509d697bf1a2f375553d5ce",
        pic_id: "cbe20c359509d697bf1a2f375553d5ce",
        lyric_id: "cbe20c359509d697bf1a2f375553d5ce",
        pic: "null",
        url: " /老歌/爱情错觉 - 王娅.mp3"
    }, {
        id: "ff73ad3464036b37feaf5d9d940fdf3f",
        name: "独角戏",
        artist: "许茹芸",
        album: "如果云知道",
        source: "kugou",
        url_id: "ff73ad3464036b37feaf5d9d940fdf3f",
        pic_id: "ff73ad3464036b37feaf5d9d940fdf3f",
        lyric_id: "ff73ad3464036b37feaf5d9d940fdf3f",
        pic: "null",
        url: " /老歌/独角戏(Live) - 许茹芸.mp3"
    }, {
        id: "cbd9a8cfaad5912915cec3fca5d3b8b9",
        name: "黄昏",
        artist: "周传雄",
        album: "transfer",
        source: "kugou",
        url_id: "cbd9a8cfaad5912915cec3fca5d3b8b9",
        pic_id: "cbd9a8cfaad5912915cec3fca5d3b8b9",
        lyric_id: "cbd9a8cfaad5912915cec3fca5d3b8b9",
        pic: "null",
        url: " /老歌/黄昏 - 周传雄.mp3"
    }]
    },
	
    // 方式二：直接提供网易云歌单ID
	{
        id: 8246775932 //实时热度榜
    },
    {
        id: 6723173524   // 网络热歌榜
    },
    {
        id: 6688069460 //听歌识曲榜
    },
	{
        id: 3061593465 //深度睡眠专用音乐
    },
    {
        id: 2829733864 //睡眠伴侣
    },
    {
        id: 26467411 //那些你熟悉却又不知道名字的轻音乐
    },
    {
        id: 8113289667 //分手快乐
    },
	{
        id: 7725920397 //徒步必听
    },
	{
        id: 2653868848 //【德语】莱茵河畔之风拂过心田
    },
    {
        id: 7489682204 //BPM 160 | 节奏有点猛 注意安全哦！
    }
    // 播放列表的最后一项大括号后面不要加逗号

];