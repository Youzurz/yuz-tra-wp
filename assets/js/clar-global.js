var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


window.CLAR = window.CLAR || {};
(function (window, document) {
    "use strict";

    var localized = (typeof CLAR !== "undefined" && CLAR) ? CLAR : {};
    if (localized && typeof localized === "object") {
        for (var key in localized) {
            if (Object.prototype.hasOwnProperty.call(localized, key)) {
                window.CLAR[key] = localized[key];
            }
        }
    }

    var readyQueue = [];
    var domReady = false;

    var flushQueue = function () {
        if (domReady) {
            while (readyQueue.length) {
                var item = readyQueue.shift();
                try {
                    item(window.CLAR);
                } catch (err) {
                    if (window.console && yuz_release_console.error) {
                        yuz_release_console.error("CLAR.ready callback error:", err);
                    }
                }
            }
        }
    };

    window.CLAR.ready = function (callback) {
        if (typeof callback !== "function") {
            return;
        }
        readyQueue.push(callback);
        flushQueue();
    };

    if (document.readyState === "complete" || document.readyState === "interactive") {
        domReady = true;
        flushQueue();
    } else {
        document.addEventListener("DOMContentLoaded", function () {
            domReady = true;
            flushQueue();
        });
    }

    if (typeof window.CLAR.toast !== "function") {
        window.CLAR.toast = function (message, options) {
            var type = options && options.type ? options.type : "info";
            if (window.console && yuz_release_console.log) {
                yuz_release_console.log("[CLAR][" + type + "]", message);
            }
        };
    }

    if (!window.CLAR.hasOwnProperty("nonce")) {
        window.CLAR.nonce = window.CLAR.nonce || {};
    }

    if (!window.CLAR.hasOwnProperty("ajax_url") && typeof window.ajaxurl !== "undefined") {
        window.CLAR.ajax_url = window.ajaxurl;
    }

    if (!window.CLAR.hasOwnProperty("cspNonce")) {
        var metaNonce = document.querySelector("meta[name='csp-nonce']");
        if (metaNonce) {
            window.CLAR.cspNonce = metaNonce.getAttribute("content") || "";
        }
    }

    window.CLAR.debug = window.CLAR.debug || false;
    if (window.CLAR.debug && window.console && yuz_release_console.info) {
        yuz_release_console.info("CLAR global fallback initialised.", window.CLAR);
    }
})(window, document);
