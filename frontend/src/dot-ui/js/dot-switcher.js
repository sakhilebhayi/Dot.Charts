/*
 * dot-ui / switcher behaviour. Plain browser JavaScript, no framework, no inline script needed (CSP friendly).
 *
 * Markup contract (see blade/switcher.blade.php): a root `[data-dsw]` holding
 *   [data-dsw-trigger]   the button        [data-dsw-panel]  the panel (starts `hidden`)
 *   [data-dsw-filter]    the text filter   [data-dsw-empty]  the "nothing matches" line (starts `hidden`)
 *   [data-dsw-live]      a polite status region
 *   a.dsw-bay[data-dsw-go]            a tile that leaves for another platform (gets "Opening...")
 *   li[data-dsw-name]                 a filterable entry (lower-case name)
 *   section[data-dsw-group]           a group, hidden when none of its tiles match
 *
 * What it does: opens and closes the panel (button, Escape with focus returned, click outside), focuses the filter on
 * open, filters tiles as you type, moves between tiles with the arrow keys, and shows "Opening..." on the tile you
 * chose. InfoDot's launch page has no failure path of its own, so the busy state is undone after a timeout and
 * whenever the page is shown again (back button, bfcache).
 */
(function () {
    'use strict';

    var BUSY_MS = 12000;

    function init(root) {
        if (!root || root.getAttribute('data-dsw-ready') === '1') {
            return;
        }

        var trigger = root.querySelector('[data-dsw-trigger]');
        var panel = root.querySelector('[data-dsw-panel]');

        if (!trigger || !panel) {
            return;
        }

        root.setAttribute('data-dsw-ready', '1');
        root.classList.add('is-ready');

        var filter = root.querySelector('[data-dsw-filter]');
        var empty = root.querySelector('[data-dsw-empty]');
        var live = root.querySelector('[data-dsw-live]');
        var busyTimer = null;

        function isOpen() {
            return !panel.hidden;
        }

        function open() {
            panel.hidden = false;
            panel.setAttribute('data-open', '');
            trigger.setAttribute('aria-expanded', 'true');

            if (filter) {
                filter.focus();
            }
        }

        function close(returnFocus) {
            panel.hidden = true;
            panel.removeAttribute('data-open');
            trigger.setAttribute('aria-expanded', 'false');

            if (returnFocus) {
                trigger.focus();
            }
        }

        trigger.addEventListener('click', function () {
            if (isOpen()) {
                close(false);
            } else {
                open();
            }
        });

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen()) {
                event.preventDefault();
                close(true);
                return;
            }

            if (!isOpen() || ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(event.key) === -1) {
                return;
            }

            var targets = visibleLinks();
            var at = targets.indexOf(document.activeElement);

            if (event.target === filter && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
                return; // the caret belongs to the field
            }

            if (!targets.length) {
                return;
            }

            event.preventDefault();

            var forward = event.key === 'ArrowDown' || event.key === 'ArrowRight';
            var next = at === -1 ? (forward ? 0 : targets.length - 1) : (at + (forward ? 1 : -1) + targets.length) % targets.length;

            targets[next].focus();
        });

        document.addEventListener('click', function (event) {
            if (isOpen() && !root.contains(event.target)) {
                close(false);
            }
        });

        function visibleLinks() {
            return Array.prototype.slice.call(panel.querySelectorAll('a.dsw-bay[href]')).filter(function (a) {
                return !a.closest('[hidden]');
            });
        }

        // ---- filter ----

        if (filter) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var shown = 0;

                Array.prototype.forEach.call(panel.querySelectorAll('[data-dsw-name]'), function (item) {
                    var match = q === '' || item.getAttribute('data-dsw-name').indexOf(q) !== -1;
                    item.hidden = !match;

                    if (match) {
                        shown += 1;
                    }
                });

                Array.prototype.forEach.call(panel.querySelectorAll('[data-dsw-group]'), function (group) {
                    group.hidden = !group.querySelector('[data-dsw-name]:not([hidden])');
                });

                var more = panel.querySelector('[data-dsw-more]');
                if (more) {
                    more.hidden = !more.querySelector('[data-dsw-name]:not([hidden])');
                    if (q !== '' && !more.hidden) {
                        more.open = true;
                    }
                }

                if (empty) {
                    empty.hidden = shown > 0;

                    if (shown === 0) {
                        var text = empty.querySelector('[data-dsw-empty-q]');
                        if (text) {
                            text.textContent = filter.value.trim();
                        }
                    }
                }
            });
        }

        // ---- "Opening..." ----

        function clearBusy() {
            window.clearTimeout(busyTimer);
            root.removeAttribute('aria-busy');

            Array.prototype.forEach.call(root.querySelectorAll('.dsw-bay.is-busy'), function (tile) {
                tile.classList.remove('is-busy');
                tile.removeAttribute('aria-busy');
            });

            if (live) {
                live.textContent = '';
            }
        }

        panel.addEventListener('click', function (event) {
            var tile = event.target.closest ? event.target.closest('a.dsw-bay[data-dsw-go]') : null;

            if (!tile) {
                return;
            }

            if (tile.classList.contains('is-busy')) {
                event.preventDefault();
                return;
            }

            // A new tab or window is not "this page leaving".
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || tile.target === '_blank') {
                return;
            }

            clearBusy();
            tile.classList.add('is-busy');
            tile.setAttribute('aria-busy', 'true');
            root.setAttribute('aria-busy', 'true');

            if (live) {
                live.textContent = 'Opening ' + (tile.getAttribute('data-dsw-label') || 'the platform') + '...';
            }

            busyTimer = window.setTimeout(clearBusy, BUSY_MS);
        });

        // A page restored from the back/forward cache comes back exactly as it was left, "Opening..." included.
        // (A normally reloaded page starts clean, and a pageshow also fires on first load, so only persisted ones.)
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                clearBusy();
            }
        });
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dsw]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.DotSwitcher = { init: init, initAll: initAll };
})();
