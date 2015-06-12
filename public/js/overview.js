$(function() {

// Configuration
var groups = {
	'Federations': [],
	'roles': ['SP', 'IDP', 'AA'],
	'Entitycategories': ['entcat:RaS', 'entcat:CoCo', 'entcat:RaS\.SE', 'entcat:.+:EC'],
	'Misc': ['approved', 'collisions:.+:Collisions', 'collisions:wayf-fed:WAYF']
};
var limit = 100;


// Build directory of federations and entities
var directory = [],
	counts = {};

for (var fed in json) {
    fedfed = 'fed:' + fed;
	directory[fedfed] = [];
	counts[fedfed] = 0;
	groups['Federations'].push(fedfed);
	for (var ent in json[fed]['entities']) {
		var e = json[fed]['entities'][ent][0]
		//e.approved = e.approved || 'N/A';
		e.displayname = '';
		if (e.collisions && e.collisions.indexOf('wayf-fed') != -1) { e.displayname = '*'; }
		e.displayname += e.collisions.length ? '* ' : '';
		//e.cols = e.collisions ? true : false;
		e.displayname += e.idpname || e.servicename || e.servicename2 || e.organisationdisplayname || '...';
		//e.displayname += ' ' + e.metadataerrors;
		e.url = '/show?entityID=' + e.entityid + '&type=' + e.type + '&fed=' + fed;
		var typeArray = [];
		e[fed] = true;
		e.el = getRow(e);
		counts[fedfed]++;
		directory[fedfed][ent] = e;
	}
}

// Get searchtext from sessionStorage and listen for searches
var searchText,
	patterns,
	buttonpatterns = {},
	regexps;
var searchInput = $('#searchInput')
	.on('input', function() {
		searchText = $(this).val();
		patterns = makePatterns(searchText);
		render();
	});

// Render buttons for filtering
var filters = $('#filters'),
	buttons = [];
var buttonContainer = $('<div></div>');
for (var g in groups) {
	var group = $('<div class="group"></div>');
	for (var i = 0; i < groups[g].length; i++) {
		var type = groups[g][i];
		count(type);
		var button = new filterButton(type);
		group.append(button.el);
		buttons.push(button);
	}
	buttonContainer.append(group);
}
filters.append(buttonContainer);
$('#searchForm').after(filters);

// Listen for clear
$('#searchClear').on('click', function() {
	sessionStorage.clear();
	loadSession();
	render();
});
// Load session and render
loadSession();
render();

// from https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_Expressions
function escapeRegExp(string){
  return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function count(text) {
    var subterms = text.split(':');
    if (subterms.length == 1) {
        subterms.push('');
        text += ':';
    }
	var tag = subterms[0];
	var val = subterms[1];
    var regexp = '^' + val + '$';
	regexps = {};
	regexps[tag] = [new RegExp(regexp, 'i')];
    var c = 0;
	for (var fed in directory) {
		for (var ent in directory[fed]) {
			if (isMatching(directory[fed][ent])) { c++; }
		}
	}
	counts[text] = c;
}

function loadSession() {
	searchText = sessionStorage.getItem('searchText') || '';
	searchInput.val(searchText);
	patterns = makePatterns(searchText);
	buttonpatterns = JSON.parse(sessionStorage.getItem('buttonpatterns')) || {};
	// remove feds not in the current page
	var newfeds = [];
	for ( f in buttonpatterns['fed'] ) {
	    if (json[g]) { newfeds.push(g); }
	}
	buttonpatterns['fed'] = newfeds;
}

function saveSession() {
	sessionStorage.setItem("buttonpatterns", JSON.stringify(buttonpatterns));
	sessionStorage.setItem('searchText', searchText);
}

function makePatterns (terms) {
	//terms = terms.replace('/[-\/\\^$*+?.()|[\]{}]/g', '\\$&'); // Escape stuff
	terms = terms.split(' ');
	var subterms;
	var patterns = {};
	for (var i = 0; i < terms.length; i++) {
	    subterms = terms[i].split(':');
	    if (subterms.length == 1) { subterms.unshift('keywords'); }
	    if (patterns[subterms[0]] == undefined) { patterns[subterms[0]] = []; }
		patterns[subterms[0]].push('^' + subterms[1]);
	}
	return patterns;
}

function isMatching (entity) {
	var found = true;
	for (var h in regexps) {
	    if (regexps[h].length == 0) { continue; }
 	    var ent = entity[h];
	    var type = typeof ent;
	    if (type === 'undefined' || ent === null) { return false; }
        if (type === 'boolean') { found = found && ent; continue; }
        if (type === 'string') { ent = [ent]; }
 	    for (var i = 0; i < regexps[h].length; i++) {
 	        var pfound = false;
            for (var j = 0; j < ent.length; j++) {
                 pfound = pfound || regexps[h][i].test(ent[j]);
             }
            found = found && pfound;
        }
	}
	return found;
}

function filterButton(text) {
	var checkOn = 'fa-check-square-o',
		checkOff = 'fa-square-o',
		el = this.el = $('<button class="tiny"></button>'),
		icon = this.icon = $('<i class="fa fa-lg"></i>');
    var subterms = text.split(':');
    if (subterms.length == 1) { subterms.push(''); text += ':'; }
	var tag = subterms[0];
	var display = subterms.length == 3 ? subterms[2] : subterms[1];
	//if (text == '.*') { text = tag; }
	el.html((display || tag)  + ' ' + counts[text] + '&nbsp;&nbsp;');
	el.append(icon);
    var regexp = '^' + subterms[1] + '$';

	var update = this.update = function() {
	    if (buttonpatterns[tag] == undefined) { buttonpatterns[tag] = []; }
		var on = buttonpatterns[tag].indexOf(regexp) != -1;
		if (on) {
			el.removeClass('secondary');
			icon.addClass(checkOn);
			icon.removeClass(checkOff);
		} else {
			el.addClass('secondary');
			icon.removeClass(checkOn);
			icon.addClass(checkOff);
		}
	}
	el.click(function() {
	    if (buttonpatterns[tag] == undefined) { buttonpatterns[tag] = []; }
		var index = buttonpatterns[tag].indexOf(regexp);
		if (index == -1)
		    buttonpatterns[tag].push(regexp);
		else
			buttonpatterns[tag].splice(index, 1);
		el.blur();
		render();
	});
}

var timeout;
function render() {

	// Update search gui
	saveSession();
	for (var i = 0; i < buttons.length; i++)
		buttons[i].update();

	// Get results
	var results = [];
	regexps = {};
	var patternlist = [ patterns, buttonpatterns ];
	for (var h = 0; h < patternlist.length; h++) {
	    currentpatterns = patternlist[h];
        for (var k in currentpatterns) {
    	    if (regexps[k] == undefined) { regexps[k] = []; }
            for (var i = 0; i < currentpatterns[k].length; i++) {
                regexps[k].push(new RegExp(currentpatterns[k][i], 'i'));
            }
        }
	}

	for (var fed in directory) {
		for (var ent in directory[fed]) {
			var e = directory[fed][ent];
//			if (isType(e) && isMatching(e)) results.push(e);
			if (isMatching(e)) results.push(e);
		}
	}
    results.sort(function(a, b){
        return a.entityid.localeCompare(b.entityid);
    });
	// Render
	var previews = $('#previews');
	var frag = $(document.createDocumentFragment());
	var stop = Math.min(limit, results.length);
	// var stop = Math.min(results.length);
	for (var i = 0; i < stop; i++)
		frag.append(results[i].el);
	previews.html(frag);

	// Lazy load trigger
	clearTimeout(timeout);
	if (i < results.length) {
		timeout = setTimeout(function() {
			for (i; i < results.length; i++)
				previews.append(results[i].el);
		}, 500);
	}

	$('#status').html(results.length + ' matches');
}

function getRow(entity) {
	var row = $('<tr></tr>');
	var labels = [];
	for (var i = 0; i < groups['roles'].length; i++) {
	    var role = groups['roles'][i];
		if (entity[role]) { labels.push(role); }
	}
	var columns = [
		entity.displayname,
		entity.entityid,
		labels.concat(entity.entcat).join(', '),
		entity.fed,
		(entity.approved ? '<i class="fa fa-lg fa-check"></i>' : ''),
		(typeof entity.schemaerrors == 'undefined' ? '-' : entity.schemaerrors) + ' / ' +
		    (typeof entity.metadataerrors == 'undefined' ? '-' : entity.metadataerrors)
	];
	for (var i = 0; i < columns.length; i++) {
		var link = $('<a></a>').html(columns[i]).attr('href', entity.url);
		var cell = $('<td></td>').html(link);
		row.append(cell);
	}
	return row.get()[0];
}

});