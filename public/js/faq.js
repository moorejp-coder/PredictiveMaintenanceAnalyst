(function () {
    var all = function () { return document.querySelectorAll('details'); };
    document.getElementById('expand-all').addEventListener('click', function () {
        all().forEach(function (d) { d.open = true; });
    });
    document.getElementById('collapse-all').addEventListener('click', function () {
        all().forEach(function (d) { d.open = false; });
    });
})();
