/**
 * Remove "Modifier:" label from cart and checkout pages
 * Finds the deepest element containing "Modifier:" and clears only that
 * Also removes empty whitespace nodes to prevent display issues
 * Does NOT remove elements to prevent conflicts with WooCommerce cart operations
 */
(function() {
    'use strict';

    // Check if we're on cart or checkout page
    function isCartOrCheckout() {
        return document.body.classList.contains('woocommerce-cart') ||
               document.body.classList.contains('woocommerce-checkout') ||
               document.body.classList.contains('woocommerce-order-received');
    }

    // Remove empty whitespace nodes recursively (optimized - only within cart items)
    function clean(node) {
        for (var n = 0; n < node.childNodes.length; n++) {
            var child = node.childNodes[n];
            if (child.nodeType === 3 && !/\S/.test(child.nodeValue)) {
                node.removeChild(child);
                n--;
            } else if (child.nodeType === 1) {
                clean(child);
            }
        }
    }

    // Remove "Modifier:" from elements
    function removeModifierLabel() {
        if (!isCartOrCheckout()) {
            return;
        }

        // Find all elements that contain "Modifier:" in their text content
        var allElements = document.body.querySelectorAll('*');
        var elementsToClear = [];

        allElements.forEach(function(element) {
            if (element.textContent && element.textContent.indexOf('Modifier:') !== -1) {
                var childHasModifier = false;
                var children = element.children;
                for (var i = 0; i < children.length; i++) {
                    if (children[i].textContent && children[i].textContent.indexOf('Modifier:') !== -1) {
                        childHasModifier = true;
                        break;
                    }
                }

                if (!childHasModifier) {
                    elementsToClear.push(element);
                }
            }
        });

        // Clear the "Modifier:" text
        elementsToClear.forEach(function(element) {
            if (element.childNodes.length === 1 && element.childNodes[0].nodeType === Node.TEXT_NODE) {
                element.textContent = '';
            } else {
                var childNodes = Array.from(element.childNodes);
                childNodes.forEach(function(node) {
                    if (node.nodeType === Node.TEXT_NODE && node.textContent.indexOf('Modifier:') !== -1) {
                        node.textContent = '';
                    }
                });
            }
        });

        // Clean up empty whitespace nodes from the entire body
        // clean(document.body);
    }

    // Run on initial load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeModifierLabel);
    } else {
        removeModifierLabel();
    }

    // Use MutationObserver to handle dynamically loaded content (AJAX, etc.)
    // Debounce the observer to prevent conflicts with WooCommerce cart updates
    var observerTimeout = null;
    var observer = new MutationObserver(function(mutations) {
        if (observerTimeout) {
            clearTimeout(observerTimeout);
        }

        observerTimeout = setTimeout(function() {
            removeModifierLabel();
        }, 100);
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true
    });
})();
