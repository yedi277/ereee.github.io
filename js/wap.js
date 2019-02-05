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
