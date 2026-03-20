var yuz_release_console={log(){},debug(){},info(){},warn(){},error(){},groupCollapsed(){},groupEnd(){},table(){}};


(function (window, document) {
    "use strict";

    var body = document.body;
    if (!body) {
        return;
    }

    var pageId = body.getAttribute("data-page-id");
    if (!pageId) {
        var queried = document.getElementById("post-" + (window.CLAR && window.CLAR.page_id ? window.CLAR.page_id : ""));
        if (queried && queried.id) {
            pageId = queried.id.replace("post-", "");
        }
    }

    if (!pageId && window.CLAR && window.CLAR.page_id) {
        pageId = String(window.CLAR.page_id);
    }

    if (!pageId) {
        pageId = "0";
    }

    body.dataset.pageId = pageId;

    if (window.CLAR) {
        window.CLAR.page_id = pageId;
    }

    if (window.CLAR && window.CLAR.debug && window.console && yuz_release_console.info) {
    }
})(window, document);
