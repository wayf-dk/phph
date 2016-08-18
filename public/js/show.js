var attrs;

$(document).ready(function() {

    // Counter
  var counter = {
    el: $("<span></span>").appendTo("#info"),
    count: 0,
    update: function () {
      count = 0;
      for (var i = 0; i < attrs.length; i++)
        if (attrs[i].granted) count++;
      this.el.html("Attributes: " + count);
    },
  };
  counter.update();
  var ids = JSON.parse(sessionStorage.getItem("show.folded")) || [];
  if (ids.length) {
    $("#" + ids.join(",#")).addClass("closed");
  }

  $(".showandhideable").click(function() {
    $(this).toggleClass("closed");
    var ids = $(".showandhideable.closed").map(function(i, x) {
      return x.getAttribute("id");}).get();
    sessionStorage.setItem("show.folded", JSON.stringify(ids));
  });


  // Append attributes to table
  var on = "fa-check-square-o",
    off = "fa-square-o",
    tbody = $("#supportedAttributes");
  attrs.forEach(function(attr) {

        // Build row
    var el = attr.el = $("<tr></tr>");
    if (attr.requested) el.addClass("requested");
    if (attr.granted) el.addClass("granted");
    el.append("<td>" + attr.friendlyName + "</td>");
    el.append("<td>" + attr.oid + "</td>");
    el.append("<td>" + (attr.requested ? '<i class="fa fa-lg fa-check"></i>' : "") + "</td>");
    el.append("<td>" + (attr.required ? '<i class="fa fa-lg fa-check"></i>' : "") + "</td>");
    el.append('<td><i class="fa fa-lg ' + (attr.granted ? on : off) + '"></i></td>');
    tbody.append(el);

        // Add event handlers
    el.bind("click", function() {
      $(this).children().last().find("i")
                .toggleClass(on)
                .toggleClass(off);
      $(this).toggleClass("granted");
      attr.granted = !attr.granted;
      counter.update();
    });
  });

  tbody = $("#unsupportedAttributes");
  xtraats.forEach(function(attr) {
    var el = attr.el = $("<tr></tr>");
    el.append("<td>" + (attr.FriendlyName || attr.Name)  + "</td>");
    el.append("<td>" + attr.NameFormat  + "</td>");
    tbody.append(el);
  });

  window.onsubmit = function() {
    var granted = [];
    for (var i = 0; i < attrs.length; i++) {
      if (attrs[i].granted) granted.push(attrs[i].friendlyName);
    }

    attrs = $(".attributes tr.granted").map(function() { return $(this).children().first().text();});
    $("#attrs").val(attrs.toArray().join(","));
        // invalidate cache
    window.name="";
  };
});