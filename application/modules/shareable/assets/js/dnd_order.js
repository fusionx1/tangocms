$(document).ready(function(){function b(c,d){$("table#shareable-sites .order "+(c=="select"?"select":"input")).each(function(a){$(this).replaceWith('<input type="text" name="'+$(this).attr("name")+'" value="'+(a+1)+'">')});$("table#shareable-sites tbody tr").each(function(a){$(this).removeClass("odd even");$(this).addClass(a%2==0?"even":"odd")});$(d).addClass("ondrop")}$("table#shareable-sites").tableDnD({onDrop:b});b("select");$("table#shareable-sites .order").hide()});
