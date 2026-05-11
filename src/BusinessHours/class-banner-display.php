<?php

/**
 * Clover Business Hours - Banner Display
 * Handles the open/closed banner display
 */

namespace Src\BusinessHours;

if (!defined('ABSPATH')) {
    exit;
}

class Banner_Display
{
    private $business_hours;

    public function __construct()
    {
        $this->business_hours = new Business_Hours();
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('wp_body_open', array($this, 'display_banner'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function enqueue_styles()
    {
        wp_register_style(
            'clover-business-hours-banner',
            false,
            array(),
            '1.0.0'
        );
        wp_enqueue_style('clover-business-hours-banner');

        // Inline critical CSS for banner - Same height for open/closed
        $css = '
            .clover-status-banner{position:fixed;left:0;right:0;text-align:center;padding:8px 15px;font-weight:600;font-family:monospace;z-index:99999;color:#fff;font-size:13px;line-height:1.4;white-space:nowrap}
            .clover-banner-open{background:linear-gradient(135deg,#28a745,#20c997)}
            .clover-banner-closed{background:linear-gradient(135deg,#dc3545,#c82333)}
            .clover-countdown{display:inline;margin-left:5px;font-size:12px;font-weight:400}
            .clover-countdown-label{margin-right:5px}
            .clover-countdown-timer{background:rgba(0,0,0,0.15);padding:2px 8px;border-radius:12px;display:inline}
            .clover-days,.clover-hours,.clover-minutes,.clover-seconds{font-weight:600;font-size:12px}
            .clover-countdown-message{display:inline;margin-left:5px;font-size:12px;font-weight:400}
            @media (max-width: 768px) {
                .clover-status-banner{white-space:normal}
                .clover-countdown{display:block;margin:5px 0 0 0}
            }
        ';
        wp_add_inline_style('clover-business-hours-banner', $css);

        // Enqueue countdown script
        wp_enqueue_script(
            'clover-countdown',
            CLOVER_PLUGIN_URL . 'src/BusinessHours/js/countdown.js',
            array('jquery'),
            '1.0.0',
            true
        );
    }

    public function display_banner()
    {
        // Check if banner is enabled
        $show_banner = get_option('clover_bh_show_banner', '1');
        if ($show_banner !== '1') {
            return;
        }

        // Check if we're on a product category page
        $category_id = null;
        if (is_product_category()) {
            $category_id = get_queried_object_id();
        }

        $status = $this->business_hours->get_business_status($category_id);
        $position = get_option('clover_bh_banner_position', 'bottom');
        $show_countdown = get_option('clover_bh_show_countdown', '1');

        if ($status['open']) {
            $class = 'clover-banner-open';
            $message = 'We are currently OPEN';
            if (!empty($status['close_time'])) {
                $message .= ' (until ' . $this->business_hours->format_minutes_to_time($status['close_time']) . ')';
            }
            $countdown_html = '';
            
            // Add closing time data for JS transition
            $next_close_attr = !empty($status['next_close']) ? ' data-next-close="' . esc_attr($status['next_close']) . '"' : '';
        } else {
            $class = 'clover-banner-closed';
            $message = 'We are currently CLOSED';

            if ($show_countdown === '1' && !empty($status['next_open']) && empty($status['error'])) {
                $countdown_html = '
                    <span class="clover-countdown" data-next-open="' . esc_attr($status['next_open']) . '">
                        <span class="clover-countdown-label"> • Opens in:</span>
                        <span class="clover-countdown-timer">
                            <span class="clover-days">0</span>d
                            <span class="clover-hours">00</span>h
                            <span class="clover-minutes">00</span>m
                            <span class="clover-seconds">00</span>s
                        </span>
                    </span>
                ';
            } else {
                $msg = !empty($status['message']) ? $status['message'] : 'Check back soon!';
                $countdown_html = '<span class="clover-countdown-message"> • ' . esc_html($msg) . '</span>';
            }
        }

        $style = $position === 'top' ? 'top:0;' : 'bottom:0;';
        ?>
        <div class="clover-status-banner <?php echo esc_attr($class); ?>" style="<?php echo esc_attr($style); ?>"<?php echo isset($next_close_attr) ? $next_close_attr : ''; ?>>
            <span class="clover-status-message"><?php echo esc_html($message); ?></span>
            <?php echo $countdown_html; ?>
        </div>
        <?php
    }
}
