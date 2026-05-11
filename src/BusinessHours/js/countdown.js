/**
 * Clover Business Hours - Countdown Timer
 */
(function($) {
    'use strict';

    function updateCountdown() {
        // 1. Check if store just closed
        var $banner = $('.clover-status-banner');
        var nextClose = $banner.data('next-close');

        if (nextClose) {
            var now = new Date().getTime();
            var closeTime = nextClose * 1000;

            if (now >= closeTime) {
                // Store is now closed! Update banner to "Closed" state
                $banner.removeClass('clover-banner-open').addClass('clover-banner-closed');
                $banner.find('.clover-status-message').text('We are currently CLOSED');
                // Remove the data attribute so we don't keep triggering this
                $banner.removeAttr('data-next-close');
                return;
            }
        }

        // 2. Check if countdown reached 0 (Store opened)
        $('.clover-countdown').each(function() {
            var nextOpen = $(this).data('next-open') * 1000;
            var now = new Date().getTime();
            var distance = nextOpen - now;

            if (distance < 0) {
                // Store is now open! Update the banner to the "Open" state
                if ($banner.length) {
                    // Change banner to open state
                    $banner.removeClass('clover-banner-closed').addClass('clover-banner-open');

                    // Update message
                    $banner.find('.clover-status-message').text('We are currently OPEN');

                    // Remove countdown
                    $(this).remove();
                }
                return;
            }

            var days = Math.floor(distance / (1000 * 60 * 60 * 24));
            var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((distance % (1000 * 60)) / 1000);

            $(this).find('.clover-days').text(days);
            $(this).find('.clover-hours').text(hours.toString().padStart(2, '0'));
            $(this).find('.clover-minutes').text(minutes.toString().padStart(2, '0'));
            $(this).find('.clover-seconds').text(seconds.toString().padStart(2, '0'));
        });
    }

    $(document).ready(function() {
        updateCountdown();
        setInterval(updateCountdown, 1000);
    });

})(jQuery);
