
(function () {
    "use strict";

    var skipLink = document.querySelector("a.skip-link");
    if (!skipLink) {
        return;
    }

    var targetId = skipLink.getAttribute("href");
    if (!targetId || targetId.charAt(0) !== "#") {
        return;
    }

    skipLink.addEventListener("click", function (event) {
        var target = document.querySelector(targetId);
        if (!target) {
            return;
        }

        target.setAttribute("tabindex", "-1");
        target.focus({ preventScroll: false });

        window.requestAnimationFrame(function () {
            target.removeAttribute("tabindex");
        });
    });
})();
