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
