// var mobileAgent = new Array("iphone", "ipod", "android", "mobile", "blackberry", "webos", "incognito", "webmate", "bada", "nokia", "lg", "ucweb", "skyfire");
// var browser = navigator.userAgent.toLowerCase();
// var isMobile = false;
// for (var i = 0; i < mobileAgent.length; i++) {
	// if (browser.indexOf(mobileAgent[i]) != -1) {
	// isMobile = true;
	// location.href = 'http://ereee.cc/';
	// break;}}//识别手机标签跳转 true false
(function (win, doc) {
if (!win.addEventListener) return;
var html = document.documentElement;
function setFont() {
    var html = document.documentElement;
    var cliWidth = html.clientWidth;
    var k = 640;
    if (cliWidth >= 640){
      cliWidth = 640;
    }
    html.style.fontSize = cliWidth / k * 100 + "px";
}
setFont();
setTimeout(function () {
    setFont();
}, 300);
doc.addEventListener('DOMContentLoaded', setFont, false);
win.addEventListener('resize', setFont, false);
win.addEventListener('load', setFont, false);
})(window, document);

function isHidden(oDiv){var vDiv = document.getElementById(oDiv);vDiv.style.display = (vDiv.style.display == 'none')?'block':'none';}
