<?php

namespace Hostinger;

use Hostinger\Admin\Options\PluginOptions;
use Hostinger\Admin\PluginSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

class ComingSoon {
    private const RESTRICTED_REST_NAMESPACES = array( 'wp/v2', 'oembed/1.0', 'wc/store' );

    /**
     * @var PluginOptions
     */
    private PluginOptions $plugin_options;

    public function __construct() {
        $plugin_settings      = new PluginSettings();
        $this->plugin_options = $plugin_settings->get_plugin_settings();

        add_action( 'wp_footer', array( $this, 'register_styles' ) );
        add_action( 'template_redirect', array( $this, 'coming_soon' ), 0 );
        add_filter( 'wp_headers', array( $this, 'modify_headers' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_api' ), 10, 3 );

        // Fix deprecated warning.
        if ( has_action( 'wp_footer', 'the_block_template_skip_link' ) ) {
            remove_action( 'wp_footer', 'the_block_template_skip_link' );
        }
    }

    /**
     * @return void
     */
    public function coming_soon(): void {
        // Do not cache coming soon page.
        if ( has_action( 'litespeed_purge_all' ) && ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        if ( ! $this->can_bypass_coming_soon() ) {
            include_once HOSTINGER_ABSPATH . 'includes/Views/ComingSoon.php';
            die;
        }
    }

    /**
     * @return void
     */
    public function register_styles(): void {
        wp_enqueue_style(
            'hostinger_main_styles',
            HOSTINGER_ASSETS_URL . '/css/coming-soon.min.css',
            array(),
            HOSTINGER_WORDPRESS_PLUGIN_VERSION
        );
    }

    /**
     * @param array $headers
     *
     * @return array
     */
    public function modify_headers( array $headers ): array {
        $headers['Cache-Control'] = 'no-cache';

        return $headers;
    }

    /** PHPCS:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found */
    public function restrict_rest_api( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
        if ( $result !== null ) {
            return $result;
        }

        if ( ! $this->is_restricted_rest_route( $request->get_route() ) ) {
            return $result;
        }

        if ( is_user_logged_in() || $this->has_valid_bypass_code() ) {
            return $result;
        }

        return new WP_Error(
            'hostinger_maintenance_mode',
            __( 'This site is in maintenance mode.', 'hostinger' ),
            array( 'status' => 503 )
        );
    }
    /** PHPCS:enable */

    private function can_bypass_coming_soon(): bool {
        $has_valid_bypass_code = $this->has_valid_bypass_code();

        if ( is_admin() ) {
            return true;
        }

        if ( current_user_can( 'update_plugins' ) ) {
            return true;
        }

        return $has_valid_bypass_code;
    }

    /** PHPCS:disable WordPress.Security.NonceVerification.Recommended */
    private function has_valid_bypass_code(): bool {
        $expected_code = $this->plugin_options->get_bypass_code();
        $bypass_code   = isset( $_COOKIE['hostinger_bypass_code'] ) ? sanitize_text_field( $_COOKIE['hostinger_bypass_code'] ) : '';

        if ( isset( $_GET['bypass_code'] ) && $expected_code === $_GET['bypass_code'] ) {
            setcookie( 'hostinger_bypass_code', $expected_code );
            $bypass_code = $expected_code;
        }

        return ! empty( $bypass_code ) && $bypass_code === $expected_code;
    }
    /** PHPCS:enable */

    private function is_restricted_rest_route( string $route ): bool {
        foreach ( self::RESTRICTED_REST_NAMESPACES as $namespace ) {
            if ( str_starts_with( strtolower( $route ) . '/', '/' . $namespace . '/' ) ) {
                return true;
            }
        }

        return false;
    }
}

new ComingSoon();
