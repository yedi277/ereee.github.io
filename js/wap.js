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
function isHidden(id){var ul = document.getElementById(id);if(ul.style.display == "block"){ul.style.display = "none";}else{ul.style.display = "block";}
function show(id){
	var ul = document.getElementById(id);
	if(ul.style.display == "block"){
		ul.style.display = "none";
	}else{
		ul.style.display = "block"; 
	}
}
