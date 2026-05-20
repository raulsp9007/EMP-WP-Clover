/**
 * Clover – Checkout Closed
 * Disables the Place Order button and shows a notice when the store is closed.
 * Works for both Classic WooCommerce checkout and WooCommerce Blocks checkout.
 *
 * Depends on cloverStoreStatus (localized via wp_localize_script):
 *   { open: bool, message: string, error: bool }
 */
(function () {
    'use strict';

    if (typeof cloverStoreStatus === 'undefined') {
        return;
    }

    // Fail open: if API errored or store is open, do nothing
    if (cloverStoreStatus.error || cloverStoreStatus.open) {
        return;
    }

    var rawMessage = cloverStoreStatus.message || 'We are currently closed';
    var fullMessage = rawMessage + '. Orders cannot be placed while the store is closed.';

    // --------------------------------------------------------
    // Selectors — classic and blocks
    // --------------------------------------------------------
    var SELECTORS = {
        classicButton: '#place_order',
        classicForm:   '#checkout',
        blocksButton:  [
            '.wc-block-components-checkout-place-order-button',
            '.wp-block-woocommerce-checkout-actions-block button[type="submit"]',
        ].join(', '),
        blocksActions: [
            '.wp-block-woocommerce-checkout-actions-block',
            '.wc-block-checkout__actions_row',
            '.wc-block-checkout__actions',
        ].join(', '),
    };

    // --------------------------------------------------------
    // Disable buttons
    // --------------------------------------------------------
    function disableButtons() {
        var all = document.querySelectorAll(
            SELECTORS.classicButton + ', ' + SELECTORS.blocksButton
        );

        all.forEach(function (btn) {
            if (btn.dataset.cloverDisabled) {
                return; // already handled
            }
            btn.disabled  = true;
            btn.style.opacity    = '0.5';
            btn.style.cursor     = 'not-allowed';
            btn.title            = fullMessage;
            btn.dataset.cloverDisabled = '1';

            // Belt-and-suspenders: also intercept click events
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }, true);
        });
    }

    // --------------------------------------------------------
    // Inject notice
    // --------------------------------------------------------
    var NOTICE_CLASS = 'clover-store-closed-notice';

    function buildNotice() {
        var div = document.createElement('div');
        div.className = 'woocommerce-error ' + NOTICE_CLASS;
        div.setAttribute('role', 'alert');
        div.style.cssText = 'margin-bottom:16px;padding:12px 16px;';
        div.textContent   = fullMessage;
        return div;
    }

    function injectNotices() {
        // -- Classic: insert before #place_order --
        var classicBtn = document.querySelector(SELECTORS.classicButton);
        if (classicBtn && !classicBtn.parentNode.querySelector('.' + NOTICE_CLASS)) {
            classicBtn.parentNode.insertBefore(buildNotice(), classicBtn);
        }

        // -- Blocks: insert at top of actions block --
        var actionsBlock = document.querySelector(SELECTORS.blocksActions);
        if (actionsBlock && !actionsBlock.querySelector('.' + NOTICE_CLASS)) {
            actionsBlock.insertBefore(buildNotice(), actionsBlock.firstChild);
        }
    }

    // --------------------------------------------------------
    // Block classic form submit (fallback safety net)
    // --------------------------------------------------------
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.id === 'checkout') {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // --------------------------------------------------------
    // Apply on DOM ready
    // --------------------------------------------------------
    function apply() {
        disableButtons();
        injectNotices();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }

    // --------------------------------------------------------
    // MutationObserver — re-apply after React re-renders
    // --------------------------------------------------------
    var applyScheduled = false;
    var observer = new MutationObserver(function () {
        if (applyScheduled) {
            return;
        }
        applyScheduled = true;
        // Debounce to avoid stampede during rapid React updates
        setTimeout(function () {
            apply();
            applyScheduled = false;
        }, 50);
    });

    observer.observe(document.body, { childList: true, subtree: true });
})();
