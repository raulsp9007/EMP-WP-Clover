/**
 * Quick View Modal - Fixed to prevent multiple submissions
 */
(function($) {
    'use strict';

    console.log('✅ Quick View JS loaded');

    // Track if controls are initialized
    var controlsInitialized = false;

    // Open quick view modal
    function openQuickView(productId) {
        console.log('🛍️ Opening Quick View for:', productId);
        
        if (typeof quickViewParams === 'undefined') {
            console.error('❌ quickViewParams not defined!');
            alert('Quick View error: Configuration missing');
            return;
        }
        
        $.ajax({
            url: quickViewParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'clover_quick_view_product',
                product_id: productId,
                nonce: quickViewParams.nonce
            },
            success: function(response) {
                console.log('✅ AJAX Success');
                if (response.success) {
                    var $modal = $('#clover-quick-view-modal');
                    $modal.html(response.data.html).fadeIn(300);
                    $('body').addClass('clover-quick-view-open');
                    console.log('✅ Modal opened');
                    
                    // Initialize controls (only once)
                    initializeQuickViewControls();
                } else {
                    console.error('❌ AJAX failed:', response.data.error);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', status, error);
            }
        });
    }

    // Close quick view
    function closeQuickView() {
        $('#clover-quick-view-modal').fadeOut(300, function() {
            $(this).empty();
            $('body').removeClass('clover-quick-view-open');
            console.log('🚪 Modal closed');
        });
    }

    // Initialize controls - only once
    function initializeQuickViewControls() {
        if (controlsInitialized) {
            console.log('⚙️ Controls already initialized, skipping');
            return;
        }
        
        controlsInitialized = true;
        console.log('⚙️ Initializing Quick View controls');
        
        var $modal = $('#clover-quick-view-modal');

        // Quantity controls - use .off() to prevent duplicate handlers
        $modal.off('click', '.quick-view-qty-btn').on('click', '.quick-view-qty-btn', function() {
            var $input = $(this).siblings('.quick-view-qty-input');
            var currentVal = parseInt($input.val()) || 1;
            var minVal = parseInt($input.attr('min')) || 1;
            
            if ($(this).data('action') === 'plus') {
                $input.val(currentVal + 1);
            } else if (currentVal > minVal) {
                $input.val(currentVal - 1);
            }
        });
        
        $modal.off('change', '.quick-view-qty-input').on('change', '.quick-view-qty-input', function() {
            var val = parseInt($(this).val()) || 1;
            var minVal = parseInt($(this).attr('min')) || 1;
            if (val < minVal) {
                $(this).val(minVal);
            }
        });
        
        // Add to cart button - use .off() to prevent duplicate handlers
        $modal.off('click', '.clover-quick-view-add-to-cart').on('click', '.clover-quick-view-add-to-cart', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var productId = $btn.data('product-id');
            var $qtyInput = $btn.closest('.clover-quick-view-footer').find('.quick-view-qty-input');
            var quantity = parseInt($qtyInput.val()) || 1;
            
            console.log('🛒 Add to Cart clicked');
            console.log('Product ID:', productId);
            console.log('Quantity:', quantity);
            
            // Collect modifiers
            var modifiers = {};
            $modal.find('.custom-modifiers-wrapper input[type="checkbox"]:checked').each(function() {
                var $checkbox = $(this);
                var nameAttr = $checkbox.attr('name');
                if (nameAttr && nameAttr.indexOf('custom_modifiers[') === 0) {
                    var match = nameAttr.match(/custom_modifiers\[(\d+)\]\[\]/);
                    if (match) {
                        var serving = match[1];
                        if (!modifiers[serving]) {
                            modifiers[serving] = [];
                        }
                        modifiers[serving].push($checkbox.val());
                    }
                }
            });
            
            console.log('📋 Modifiers:', modifiers);
            
            // Prevent multiple clicks
            if ($btn.prop('disabled')) {
                console.log('⚠️ Button already disabled, ignoring click');
                return;
            }
            
            $btn.prop('disabled', true).text('Adding...');
            
            $.ajax({
                url: quickViewParams.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'clover_quick_view_add_to_cart',
                    product_id: productId,
                    quantity: quantity,
                    modifiers: modifiers,
                    nonce: quickViewParams.nonce
                },
                success: function(response) {
                    console.log('✅ Add to Cart Response:', response);
                    if (response.success) {
                        $btn.text('Added!');
                        setTimeout(function() {
                            $btn.prop('disabled', false).text('Add to Cart');
                            closeQuickView();
                            $(document.body).trigger('wc_fragment_refresh');
                        }, 1000);
                    } else {
                        $btn.prop('disabled', false).text('Add to Cart');
                        alert(response.data.error || 'Error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Add to Cart Error:', status, error);
                    $btn.prop('disabled', false).text('Add to Cart');
                    alert('Error: ' + error);
                }
            });
        });
        
        // Close handlers
        $(document).off('click', '.clover-quick-view-close').on('click', '.clover-quick-view-close', function(e) {
            e.preventDefault();
            closeQuickView();
        });
        
        $(document).off('click', '#clover-quick-view-modal').on('click', '#clover-quick-view-modal', function(e) {
            if ($(e.target).is('#clover-quick-view-modal')) {
                closeQuickView();
            }
        });
        
        $(document).off('keydown').on('keydown', function(e) {
            if (e.key === 'Escape' && $('#clover-quick-view-modal').is(':visible')) {
                closeQuickView();
            }
        });
        
        console.log('✅ Event handlers attached');
    }

    // Initialize on document ready
    $(document).ready(function() {
        console.log('📄 Document Ready');
        
        // Check if modal exists
        var $modal = $('#clover-quick-view-modal');
        console.log('📦 Modal exists:', $modal.length > 0);
        
        // Attach button click handler
        $(document).off('click', '.clover-quick-view-btn').on('click', '.clover-quick-view-btn', function(e) {
            e.preventDefault();
            var productId = $(this).data('product-id');
            console.log('🖱️ Quick View Button Clicked! Product ID:', productId);
            
            if (!productId) {
                console.error('❌ No product ID');
                return;
            }
            
            openQuickView(productId);
        });
        
        console.log('✅ Quick View initialized');
    });

})(jQuery);
