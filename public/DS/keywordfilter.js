// Filter an array of records by the terms (string, actual terms separated by spaces), using the aliases to map from userterms to possible actual keys
// A term is a [<searchkey>:]<searchpattern>. If no searchkey is provided it defaults to 'keywords' which might be aliased
// Patterns is a map of prepared search regexps - can be used in cases where you don't want the default behavior for search
// Aliases contains a map of <searchkey> to actual key used in the objects in records
// RegExp quoting from:
// http://closure-library.googlecode.com/git-history/docs/local_closure_goog_string_string.js.source.html#line1021

function keywordFilter(termstring, records, aliases) { // eslint-disable-line no-unused-vars
  terms = termstring.trim().split(/\s+/);
  var patterns = {};
  for (var i = 0; i < terms.length; i++) {
    var subterms = terms[i].split(":");
    if (subterms.length > 1) {
      // we have a keyname - is it one we use the the records? if not it is part of the searchterm and the key is keywords
      if (subterms[0] in records[0]) { subterms = [subterms[0], subterms.slice(1).join(":")]; }
      else {                           subterms = ["keywords", subterms.slice(0).join(":")]; }
    } else { // no key - use keywords
      subterms.unshift("keywords");
    }
    var not = subterms[1].indexOf("!") === 0;
    if (not) { subterms[1] = subterms[1].substr(1); }

    if (subterms[1][0] != "^" && subterms[1].slice(-1) != "$") { // non pre-regexps - escape them
      subterms[1] = "\\b" + subterms[1].replace(/([-()\[\]{}+?*.$\^|,:#<!\\])/g, "\\$1").replace(/\x08/g, "\\x08"); // eslint-disable-line no-control-regex
    }
    var alias = aliases[subterms[0]];
    if (alias)  { subterms[0] = alias; }
    if (patterns[subterms[0]] == undefined) { patterns[subterms[0]] = []; }
    patterns[subterms[0]].push({re: new RegExp(subterms[1] , "i"), not: not});
  }

  var result = [];
  rec: for (i = 0; i < records.length; i++) {
    var found = true;
    record = records[i];
    for (var key in patterns) {
      if (!found) { continue rec; } // stop testing if we know the rec is not going to make it
      var value = record[key];
      if (value == undefined) { continue rec; } // not found
      var type = typeof value;
      if (type === "boolean") { found = found && value != patterns[key][0]["not"]; continue; }
      if (type === "string") { value = [value]; }
      for (var h = 0; h < patterns[key].length; h++) {
        var pfound = false;
        for (var j = 0; j < value.length; j++) {
          if (pfound) { break; }
          pfound = pfound || patterns[key][h]["re"].test(value[j]);
        }
        found = found && (pfound != patterns[key][h]["not"]); // poor mans xor
      }
    }
    if (found) { result.push(i); }
  }
  return result;
}
