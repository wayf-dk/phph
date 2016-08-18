// Vue.config.debug = true;
// Helpers

var compare = (function () {
  // This is the interval comparison function
  // With `~` the first and second works as the start and end interval, third
  // being the value to check if within interval. Works like:
  //   `compare("~", 1, 10, 5)`
  // The interval is defined as [a, b). In the example it would be [1, 10)
  var compare = function (operator, first, second, third) {
    if (operator === "~")  {
      var sec = second.length ? second > third.slice(0, second.length) : true;
      return first <= third.slice(0, first.length) && sec;
    }

    // TODO: Would a runtime error be better so in case of a bad operator, error
    // handling would be possible?
    return false;
  };

  // This is the supported comparisons
  compare.operators = ["~"];

  return compare;
})();

function unique(array) {
  return array.filter( function (value, index, self) {
    return self.indexOf(value) === index;
  });
}

function zzzz() {
  var match,
    pl     = /\+/g,  // Regex for replacing addition symbol with a space
    search = /([^&=]+)=?([^&]*)/g,
    decode = function (s) { return decodeURIComponent(s.replace(pl, " ")); },
    q  = window.location.search.substring(1);

  urlParams = {};
  while (match = search.exec(q)) { // eslint-disable-line no-cond-assign
    urlParams[decode(match[1])] = decode(match[2]);
  }
  return urlParams;
}

function flatten(arrays) {
  return [].concat.apply([], arrays);
}

function entity(e) {
  e.displayName = e.idpname || e.servicename || e.servicename2 || e.organisationdisplayname || "...";
  e.displayName  = e.displayName.trim();

  e.url = "/show?entityID=" + e.entityid + "&type=" + e.type + "&fed=" + e.fed;
  e.fedurl = "/mdfileview?type=" + e.type + "&fed=" + e.fed;

  e.displayFederation = e.fed + (e.collisions.length !== 0 ? " [" + e.collisions.length + "]" : "");

  e.displayEntityId = e.entityid;

  e.roles = ["IDP", "SP", "AA"].reduce(function(array, role) {
    return e[role] ? array.concat(role) : array;
  }, []);
  //e.roles = e.roles.concat(e.entcat).sort();
  e.displayRoles = e.roles.join(" ");
  e.displayEncats = e.entcat.join(" ");

  e.displayApproved = e.approved ? "✔" : "";

  e.displayErrors  = e.schemaerrors ? e.schemaerrors : "-";
  e.displayErrors += " / ";
  e.displayErrors += e.metadataerrors ? e.metadataerrors : "-";

  var domains = e.entityid.match(/((?:[\w-\.]+\.[\w]+)+)+/g);
  if (domains) {
    domains = domains.map(function(w) { return "." + w; });
    e.keywords.unshift.apply(e.keywords, domains);
  }
  e.keywords.unshift(e.entityid);
  e.keywords.unshift(e.fed);
  e.alsoin = "This entityID ";
  e.alsoin += e.collisions.length ? "also appears in: " + e.collisions.join(", ") : "does not appear in other federations";
  return e;
}

// Flatten the JSON object into an array of entities
function entityList(jsonData) {
  var entities = Object.keys(jsonData).reduce(function(array, federation) {
    var fedEntities = jsonData[federation].entities;
    // groups.federations.push('fed:' + federation);
    var entities = Object.keys(fedEntities).reduce(function(a, entity) {
      return a.concat(fedEntities[entity][0]);
    }, []);
    return array.concat(entities);
  }, []);

  return entities.map(entity);
}

// Filter an array of records by the terms (string, actual terms separated by spaces), using the aliases to map from userterms to possible actual keys
// A term is a [<searchkey>:]<searchpattern>. If no searchkey is provided it defaults to 'keywords' which might be aliased
// Patterns is a map of prepared search regexps - can be used in cases where you don't want the default behavior for search
// Aliases contains a map of <searchkey> to actual key used in the objects in records
// RegExp quoting from:
// http://closure-library.googlecode.com/git-history/docs/local_closure_goog_string_string.js.source.html#line1021
function keywordFilter(termstring, records, aliases) {
  var terms = unique(termstring.trim().split(/\s+/));
  var patterns = {};
  for (var i = 0; i < terms.length; i++) {
    var subterms = terms[i].split(":");
    if (subterms.length > 1) {
      // we have a keyname - is it one we use in the records? if not it is part of the searchterm and the key is keywords
      if (subterms[0] in records[0]) { subterms = [subterms[0], subterms.slice(1).join(":")]; }
      else {                           subterms = ["keywords", subterms.slice(0).join(":")]; }
    } else { // no key - use keywords
      subterms.unshift("keywords");
    }
    var not = subterms[1].indexOf("!") === 0;
    if (not) { subterms[1] = subterms[1].substr(1); }

    // Finds the first operator specified
    var operator = compare.operators.find(function(op) {
      return subterms[1].indexOf(op) === 0;
    });

    if (operator) {
      subterms[1] = subterms[1].substr(operator.length);
    } else if (subterms[1][0] != "^" && subterms[1].slice(-1) != "$") { // non pre-regexps - escape them
      subterms[1] = "\\b" + subterms[1].replace(/([-()\[\]{}+?*.$\^|,:#<!\\])/g, "\\$1").replace(/\x08/g, "\\x08"); // eslint-disable-line no-control-regex
    }
    var alias = aliases[subterms[0]];
    if (alias)  { subterms[0] = alias; }
    if (patterns[subterms[0]] == undefined) { patterns[subterms[0]] = []; }
    patterns[subterms[0]].push({re: new RegExp(subterms[1] , "i"), not: not, op: operator, value: subterms[1]});
  }

  var result = [];
  rec: for (i = 0; i < records.length; i++) {
    var found = true;
    record = records[i];
    for (var key in patterns) {
      if (!found) { continue rec; } // stop testing if we know the rec is not going to make it
      var value = record[key];
      if (value == undefined) { found = found && not; continue; } // not found
      var type = typeof value;
      if (type === "boolean") { found = found && value != patterns[key][0]["not"]; continue; }
      if (type === "string" || type === "integer") { value = [value]; }
      for (var h = 0; h < patterns[key].length; h++) {
        var pfound = false;
        for (var j = 0; j < value.length; j++) {
          if (pfound) { break; }
          var p = patterns[key][h];
          if (p.op) {
            var values = p.value.split(",");
            pfound = compare("~", values[0], values[1], value[j]);
          } else {
            pfound = patterns[key][h]["re"].test(value[j]);
          }
        }
        found = found && (pfound != patterns[key][h]["not"]); // poor mans xor
      }
    }
    if (found) { result.push(record); }
  }
  return result;
}

function buttons() {
  var buttons = Object.keys(groups).map(function(group) {
    return groups[group].map(function(groupItem) {
      var subterms = groupItem.split(";");
      var label = subterms.pop();
      var filter = subterms.join(" ");
      var counts = keywordFilter(filter, entities, {}).length;
      return {
        text: label + " " + counts,
        filter: filter,
        checked: false,
      };
    });
  });
  return buttons;
}

function get(url) {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.open("GET", url, false);
  xmlhttp.send(null);
  return xmlhttp.responseText;
}

var entities;
var usecache = ["/", "/overview"].indexOf(window.location.pathname) >= 0; // only for large /overview - does not use querystring - all others must bypass the cache
var refresh = true;

if (window.name && usecache) {
  entities = JSON.parse(window.name);
  entities = entities[window.location.hostname];
  refresh = entities == undefined;
}
if (refresh) {
  entities = entityList(JSON.parse(get("/overviewjs?" + window.location.search.substring(1))));
  entities.sort(function(a, b) {
    return a.entityid.localeCompare(b.entityid);
  });
  if (usecache) {
    var ent = {};
    ent[window.location.hostname] = entities;
    window.name = JSON.stringify(ent);
  }
}

var params = zzzz();

var tableContainer = null;
var rowHeight = 27;

var vm = new Vue({
  el: "#app",
  data: {
    search: "",
    start: 0,
    end: 1,
    offset: "0px",
    divOffset: "0px",
    divHeight: "500px",
    buttons: buttons(),
  },
  ready: function() {
    if (params["filter"] && sessionStorage.getItem("filter") != params["filter"]) {
      this.search = params["filter"];
    } else {
      var btns = JSON.parse(sessionStorage.getItem("buttons")) || [];
      for (var i = 0; i < btns.length; i++) {
        for (var j = 0; j < btns[i].length; j++) {
          this.buttons[i][j].checked = btns[i][j].checked;
        }
      }
      this.search  = sessionStorage.getItem("search") || this.search;
    }
  },
  methods: {
    clear: function() {
      this.buttons = buttons();
      this.search = "";
    },
    scroll: function() {
      var divOffset = this.divOffset = tableContainer.scrollTop;
      var divHeight = this.divHeight = tableContainer.getBoundingClientRect().height;

      this.start = Math.floor(divOffset / rowHeight);
      this.end = this.start + Math.ceil(divHeight / rowHeight) + 50;

      this.offset = -(divOffset % rowHeight) + "px";
      this.divOffset = divOffset + "px";
    },
  },
  computed: {
    entities: function() {
      var search = flatten(this.buttons).map(function(button) {
        if (button.checked) { return button.filter; }
        return "";
      });
      var searchtext = unique((search.join(" ") + " " + this.search).trim().split(/\s+/)).join(" ");

      var params = zzzz();
      delete params["filter"];
      if (searchtext) {
        sessionStorage.filter = params["filter"] = searchtext;
      }
      var delim = "";
      var q = "?";
      for (var x in params) {
        q += delim + x + "=" + encodeURIComponent(params[x]);
        delim = "&";
      }
      history.replaceState({}, "", q);
      var res = keywordFilter(searchtext, entities, {});
      return res;
    },
    tableHeight: function() {
      return (1 + (this.entities.length + 1) * rowHeight) + "px";
    },
  },
  watch: {
    entities: function() {
      sessionStorage.setItem("search", vm.search);
      sessionStorage.setItem("buttons", JSON.stringify(vm.buttons));
    },
  },
});


tableContainer = document.querySelector(".table-container");
tableContainer.style.height = (window.innerHeight - tableContainer.offsetTop - 25) + "px";
window.addEventListener("resize", function() {tableContainer.style.height = (window.innerHeight - tableContainer.offsetTop - 25) + "px";}, false);
rowHeight = document.querySelector(".entities tr").getBoundingClientRect().height;

vm.scroll();
