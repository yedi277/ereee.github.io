var mobileAgent = new Array("iphone", "ipod", "android", "mobile", "blackberry", "webos", "incognito", "webmate", "bada", "nokia", "lg", "ucweb", "skyfire");
var browser = navigator.userAgent.toLowerCase();
var isMobile = false;
for (var i = 0; i < mobileAgent.length; i++) {
	if (browser.indexOf(mobileAgent[i]) != -1) {
	isMobile = false;
	location.href = 'http://ereee.cc/index5.html';
	break;}}//识别手机标签跳转 

!function(){
	if(/iphone|ios|android|ipod/i.test(navigator.userAgent.toLowerCase()) == false){
		location.href = 'http://ereee.cc/';
	}
!function(n) {
    var e = n.document,
    t = e.documentElement,
    i = 640,
    d = i / 100,
    o = "orientationchange" in n ? "orientationchange": "resize",
    a = function() {
        var n = t.clientWidth || 320;
        n > 640 && (n = 640),
        t.style.fontSize = n / d + "px"
    };
    e.addEventListener && (n.addEventListener(o, a, !1), e.addEventListener("DOMContentLoaded", a, !1))
} (window);	a

function isHidden(oDiv){var vDiv = document.getElementById(oDiv);vDiv.style.display = (vDiv.style.display == 'none')?'block':'none';}
