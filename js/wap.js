!function(){
	if(/iphone|ios|android|ipod/i.test(navigator.userAgent.toLowerCase()) == false){
		location.href = 'http://ereee.cc/';
	}
function show(id){
	var ul = document.getElementById(id);
	if(ul.style.display == "block"){
		ul.style.display = "none";
	}else{
		ul.style.display = "block"; 
	}
}
