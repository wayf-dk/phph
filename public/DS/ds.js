window.ds = function(idpinfo, spinfo) {

  var urlParams = this.urlParams = parseQuery(window.location.search);
  var request = null;
  var idplist = [];
  var choosen = [];
  var maxrememberchoosen = 3;
  var rows = [];
  var searchInput = document.getElementById("searchInput");
  //searchInput.value = localStorage.query || "";
  //searchInput.selectionStart = searchInput.selectionEnd = searchInput.value.length;

  var clusterize = new Clusterize({
    rows: [],
    scrollId: "scrollArea",
    contentId: "contentArea",
    show_no_data_row: false,
  });

  var choosenentityIDs = JSON.parse(localStorage.entityID || "[]");
  var lastChosen = localStorage.lastChosen || null;
  var selectable = 0;

  // automatically jumps to the bottom of the page for a better mobile experience
  // window.scroll(0, document.body.scrollHeight);

  var entityid = urlParams.entityID;

  if (urlParams['return']) {
  // WAYF specific hack to get to the original SP
    var authid = parseQuery(new URL(urlParams['return']).search)['AuthID'];
    if (authid) {
      authid = authid.split(':').slice(1).join(':');
      entityid = parseQuery(new URL(authid).search)['spentityid'];
    }
  }

  var shaObj = new jsSHA("SHA-1", "TEXT");
  shaObj.update(entityid);
  var hash = shaObj.getHash("HEX");
  var spURL = spinfo + "{sha1}" + hash;
  searchInput.addEventListener("input", search, false);
  initsearch();

  ajax(spURL, function(err, res) {
    if (err) {
      console.log('err', err, err.response);
      //return;
    }
    if (res) {
      var metadata = new DOMParser().parseFromString(res.responseText, "text/xml");
      var xp = DOMXPath(metadata, resolver);
      var icon = xp.query("md:SPSSODescriptor/md:Extensions/mdui:UIInfo/mdui:Logo", metadata.documentElement);
      if (icon.snapshotLength) {
          spIcon.src = icon.snapshotItem(0).textContent;
          spIcon.style.display = "block";
      }
      var displayName;
      var langs = [lang, "en"];
      for (var j=0; j < langs.length; j++) {
          displayName = xp.query(`//mdui:DisplayName[@xml:lang="${langs[j]}"]` , metadata.documentElement);
          if (displayName.snapshotLength) {
              spName.textContent = displayName.snapshotItem(0).textContent;
              break;
          }
      };
    }
  });

  function initsearch() {
    if (!request) {
        request = ajax(idpinfo, function(err, res) {
          if (err) {
            console.log(err.response);
            //return;
          }
          if (res) {
            idplist = JSON.parse(res.responseText);
            readysearch();
          }
       });
    }
  }

  function readysearch() {
      //var fedsfilter = "feds:^(" + urlParams["feds"].split(/,/).join("|") + ")$";
      var fedsfilter = 'feds:^wayf$';

      var res = keywordFilter(fedsfilter, idplist, {});
      var newidplist = [];
      for (var j = 0; j < res.length; j++) {
        newidplist.push(idplist[res[j]]);
      }

      idplist = newidplist;

      var langs = [lang];
      var j = 0;
      var scrollArea = document.getElementById("scrollArea");
      var widthOfScrollArea = scrollArea.getBoundingClientRect().width;
      var charactersToCut;
      if (widthOfScrollArea <= 320) {
        charactersToCut = 25;
      } else if (widthOfScrollArea > 320 && widthOfScrollArea <= 600) {
        charactersToCut = 60;
      } else {
        charactersToCut = 80;
      }

      for (var i = 0; i < idplist.length; i++) {
        var name = "";
        if (!idplist[i].DisplayNames) { idplist[i].DisplayNames = {en: "no-name"}; }
        // add lowercase latinized names to kv list
        for (var kvname in idplist[i].DisplayNames) {
            kvnames = idplist[i].DisplayNames[kvname].latinise().toLowerCase().split(' ');
            kvnames.forEach(function (name) {
                name = name.replace(/[^\w\.\-']/, "");
                if (idplist[i].kv.indexOf(name) == -1) { // only if not there already
                    idplist[i].kv.push(name);
                }
            });
        }

        for (var l = 0; l < langs.length; l++) {
          name = idplist[i].DisplayNames[langs[l]];
          if (name !== "undefined") {
            break;
          }
        }
        if (name === undefined) {
          name = idplist[i].DisplayNames[Object.keys(idplist[i].DisplayNames)[0]];
        }
        name = name.trim();
        if (name.length > charactersToCut) {
          name = name.slice(0, charactersToCut) + "...";
        }
        idplist[i].DisplayNames = name;

        idplist[i].entityID = idplist[i].entityID[0];
        j++;
      }

      idplist = idplist.sort(function (a, b) {
        return a.DisplayNames.localeCompare(b.DisplayNames, lang);
        //return a.DisplayNames < b.DisplayNames ? -1 : 1;
      });


      var lasti;
      var oldi;
      for (i = 0; i < idplist.length; i++) {
        var title = idplist[i].kv.join(" ");
        title = title.replace(/"/g, "&quot;");
        name = idplist[i].DisplayNames;
        if ((oldi = choosenentityIDs.indexOf(idplist[i].entityID)) >= 0) { choosen[oldi] = i; }
        if (lastChosen == idplist[i].entityID) { lasti = choosenentityIDs.indexOf(lastChosen); }
        idplist[i].display = `<div class="unchoosen" title="unremember this IdP"><div class="idp" title="${title}" data-no="${i}">${name}</div></div>`;
      }

      choosen = choosen.filter(function(n){ return n != undefined; }); // remove entities not active any more

      search(); // resetting selectable = first in search list - not the chosen list
      setselectable(0); // set to 1st in choosen
      setselectable(lasti); // move to the one last choosen

      document.getElementById("contentArea").addEventListener("click", choose, false);
      document.getElementsByTagName("body")[0].addEventListener("keydown", enter, false);
  }

  function setselectable(no) { // no == -1 prev, 0 1st in list, 1 next, null 1st in filterd list
    var sel = document.getElementById("contentArea").children[selectable];
    if (sel != null) {
      sel.firstChild.className = "idp";
      sel.classList.remove("selected");
    }
    if (no == null) { // after search set to 1st in filtered list
      selectable = choosen.length;
      no = 1;
    } else {
      selectable += no ; // move forth or back
    }
    if (selectable < 0 || no == 0) { selectable = 0; } // if at start or before - move to 1st line
    if (selectable == choosen.length && choosen.length > 0) { selectable += no; } // if at delimiterline move one forth or back
    if (selectable >= rows.length) { selectable = 0; } // if at end move to start
    sel = document.getElementById("contentArea").children[selectable];
    if (sel) {
      sel.classList.add("selected");
      sel.firstChild.className = "sel0 idp";
    }
  }

  function choose(e) {
    var no, target = null;
    if (e == null) { // enter pressed
      target = document.getElementById("contentArea").children[selectable].firstChild;
    } else {
      target = e.target;
    }
    no = target.attributes.getNamedItem("data-no");
    if (no == null) { // not an IdP element - might be a choosen wrapper
      if (target.classList.contains("choosen")) { // delete one already choosen
        tobedeleted = parseInt(target.firstChild.attributes.getNamedItem("data-no").value);
        choosen = choosen.filter(function (i) { return tobedeleted != i; });
      } else {
        return;
      }
    } else { // return with selected IdP
      no = parseInt(no.value);
      localStorage.lastChosen = idplist[no].entityID;
      var i = choosen.indexOf(no);
      if (i < 0) { // new one remember it
        choosen.unshift(no);
      }
    }
    var result = [];
    var entityIDs = [];
    choosen.forEach(function(item) {
      result.push(item);
      entityIDs.push(idplist[item].entityID);
    });
    choosen = result.slice(0, maxrememberchoosen);
    entityIDs = entityIDs.slice(0, maxrememberchoosen);
    localStorage.entityID = JSON.stringify(entityIDs);
    if (no == null) { // update display
      selectable = Math.max(0, Math.min(choosen.length - 1, selectable));
      var lastselected = selectable;
      search();
      setselectable(0);
      setselectable(lastselected);
    } else { // return with result
      alert("You are being sent to " + idplist[no].DisplayNames + " (" + idplist[no].entityID + ")");
      window.location = window.location;
      //var idp = idplist[no].entityID.replace(/birk\.wayf\.dk\/birk\.php\//, '');
      //window.location = urlParams['return'] + '&' + urlParams['returnIDParam'] + '=' + encodeURIComponent(idp);
    }
  }

  function search() {
    if (idplist.length == 0) {
        //initsearch();
        //return;
    }

    rows = [];
    // start with the choosen ones ...
    choosen.forEach(function (j) {rows.push(idplist[j].display);});
    if (choosen.length) rows.push("<hr>");

    var query = searchInput.value.latinise();

    var res = keywordFilter(query, idplist, {id: "entityID", keywords: "kv"});

    for (var j = 0; j < res.length; j++) {
      if (choosen.indexOf(res[j]) != -1) { continue; } // don't display the same entity twice
      rows.push(idplist[res[j]].display);
    }

    document.getElementById("found").innerHTML = res.length;
    clusterize.update(rows);
    // the choosen ones have the right to be forgotten - ie show the eraser character
    choosen.forEach(function (j, i) {document.getElementById("contentArea").children[i].className = "choosen";});
    // set selectable to 1st filtered
    setselectable(null);
  }

  function enter(e) { // eslint-disable-line no-unused-vars
    var keyCodeMappings = {
      13: "enter",
      27: "escape",
      38: "up",
      40: "down",
    };

    var keyPressed = keyCodeMappings[e.keyCode];
    var top;

    if (e.defaultPrevented) {
      return; // Should do nothing if the key event was already consumed.
    }

    switch (keyPressed) {
      case "down":
        setselectable(1);
        break;
      case "up":
        setselectable(-1);
        break;
      case "enter":
        choose(null);
        break;
      case "escape":
        searchInput.value = "";
        search();
        break;
      default:
        return; // Quit when this doesn't handle the key event.
    }

    var doc = document.getElementById("scrollArea");
    top = 14;
    var bot = doc.clientHeight - top;
    var seltmp = selectable + (choosen.length ? 0 : 1);
    var scrolltop = (clusterize.options.item_height * seltmp);
    if (scrolltop > clusterize.scroll_elem.scrollTop + bot) {
      clusterize.scroll_elem.scrollTop = scrolltop - bot;
    } else if (scrolltop < clusterize.scroll_elem.scrollTop + top) {
      clusterize.scroll_elem.scrollTop = scrolltop - top;
    }
    e.preventDefault();
  }

  function ajax(url, callback) {
    var request = new XMLHttpRequest();

    request.onreadystatechange = function() {
      if (request.readyState == XMLHttpRequest.DONE) {
        if (request.status >= 200 && request.status < 400) {
          callback(null, request);
        } else {
          callback(request);
        }
      }
    };

    request.open("GET", url, true);
    request.send();
    return request;
  }

  function DOMXPath(xmlDoc, res) {
      return {
          query: function(xpath, context) {
              return xmlDoc.evaluate(
                  xpath,
                  context,
                  res,
                  XPathResult.ORDERED_NODE_SNAPSHOT_TYPE,
                  null
              );
          },
          document: xmlDoc,
      };
  }

  function resolver(ns) {
      var names = {
          "md": "urn:oasis:names:tc:SAML:2.0:metadata",
          "mdui": "urn:oasis:names:tc:SAML:metadata:ui",
          "xml": "http://www.w3.org/XML/1998/namespace",
      };

      return names[ns] || null;
  }

  function parseQuery(query) {
    var urlParams = {};
    var match;
    var pl = /\+/g;  // Regex for replacing addition symbol with a space
    var re = /([^&=]+)=?([^&]*)/g;
    var decode = function (s) { return decodeURIComponent(s.replace(pl, " ")); };
    query = query.replace(/^\?/, '');
    while (match = re.exec(query)) { // eslint-disable-line no-cond-assign
      urlParams[decode(match[1])] = decode(match[2]);
    }

    return urlParams;
  }
};

