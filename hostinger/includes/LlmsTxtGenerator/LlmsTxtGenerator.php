<?php

namespace Hostinger\LlmsTxtGenerator;

use Hostinger\Admin\PluginSettings;

defined( 'ABSPATH' ) || exit;

class LlmsTxtGenerator {

    public const HOSTINGER_LLMSTXT_SIGNATURE               = '[comment]: # (Generated by Hostinger Tools Plugin)';
    protected const UTF8_BOM                               = "\xEF\xBB\xBF";
    protected const HOSTINGER_LLMSTXT_SUPPORTED_POST_TYPES = array(
        'post',
        'page',
        'product',
    );

    /**
     * Activation and deactivation hooks appears too early,
     * so the plugin status is not available yet.
     *
     * That's why we use a flag this->woocommerce_status to know the plugin status.
     *
     * @var string|null
     */
    protected ?string $woocommerce_status = null;
    protected PluginSettings $plugin_settings;
    protected LlmsTxtFileHelper $file_helper;

    public function __construct( PluginSettings $plugin_settings, LlmsTxtFileHelper $llmstxt_file_helper ) {
        $this->plugin_settings = $plugin_settings;
        $this->file_helper     = $llmstxt_file_helper;
        add_action( 'init', array( $this, 'init' ) );
    }

    public function on_woocommerce_activation(): void {
        $this->woocommerce_status = 'active';
        $this->generate();
    }

    public function on_woocommerce_deactivation(): void {
        $this->woocommerce_status = 'inactive';
        $this->generate();
    }

    public function init(): void {
        if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->plugin_settings->get_plugin_settings();

        if ( $settings->get_enable_llms_txt() && ! $this->file_helper->llmstxt_file_exists() ) {
            $this->generate();
        }

        $this->init_hooks();
    }

    public function on_settings_update( bool $is_enabled ): void {
        if ( $is_enabled ) {
            $this->generate();
        } else {
            $this->delete();
        }
    }

    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( ! $this->is_post_type_supported( $post->post_type ) ) {
            return;
        }

        if ( $new_status === 'publish' || $old_status === 'publish' ) {
            $this->generate();
        }
    }

    public function on_blog_change( mixed $old_value, mixed $new_value ): void {
        if ( $old_value !== $new_value ) {
            $this->generate();
        }
    }

    public function get_content(): string {
        $content  = self::UTF8_BOM;
        $content .= $this->inject_title();
        $content .= $this->inject_site_description();
        $content .= $this->inject_items( $this->get_by_post_type( 'post' ), 'Posts' );
        $content .= $this->inject_items( $this->get_by_post_type( 'page' ), 'Pages' );
        $content .= $this->maybe_inject_woocommerce_products();
        $content .= $this->inject_signature();

        return $content;
    }

    public function init_hooks(): void {
        add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );
        add_action( 'hostinger_tools_setting_enable_llms_txt_update', array( $this, 'on_settings_update' ) );
        add_action( 'update_option_blogname', array( $this, 'on_blog_change' ), 10, 2 );
        add_action( 'update_option_blogdescription', array( $this, 'on_blog_change' ), 10, 2 );
        add_action( 'activate_woocommerce/woocommerce.php', array( $this, 'on_woocommerce_activation' ) );
        add_action( 'deactivate_woocommerce/woocommerce.php', array( $this, 'on_woocommerce_deactivation' ) );
    }

    protected function generate(): void {
        $this->file_helper->create( $this->get_content() );
    }

    protected function delete(): void {
        $this->file_helper->delete();
    }

    protected function is_post_type_supported( string $post_type ): bool {
        return in_array( $post_type, self::HOSTINGER_LLMSTXT_SUPPORTED_POST_TYPES, true );
    }

    protected function get_by_post_type( string $post_type, int $limit = 100 ): array {
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => apply_filters( 'hostinger_llmstext_item_limit', $limit, $post_type ),
        );

        return get_posts( $args );
    }

    protected function is_woocommerce_active(): bool {
        return ( is_null( $this->woocommerce_status ) && is_plugin_active( 'woocommerce/woocommerce.php' ) ) || $this->woocommerce_status === 'active';
    }

    protected function inject_site_description(): string {
        $description = get_bloginfo( 'description' );

        return $description ? "> $description\n\n" : '';
    }

    protected function inject_title(): string {
        $title = get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : site_url();

        return "# $title\n\n";
    }

    protected function inject_signature(): string {
        return "\n\n" . self::HOSTINGER_LLMSTXT_SIGNATURE;
    }

    protected function maybe_inject_woocommerce_products(): string {
        if ( ! $this->is_woocommerce_active() ) {
            return '';
        }

        return $this->inject_items( $this->get_by_post_type( 'product' ), 'Products' );
    }

    protected function inject_items( array $items, string $title ): string {
        if ( empty( $items ) ) {
            return '';
        }

        $content = "\n## $title\n\n";

        foreach ( $items as $item ) {
            $post      = get_post( $item );
            $title     = $post->post_title;
            $permalink = get_permalink( $post );
            $excerpt   = $this->prepare_excerpt( $post );

            $content .= "- [$title]($permalink)";
            if ( $excerpt ) {
                $content .= ": $excerpt";
            }

            $content .= "\n";
        }

        return $content;
    }

    protected function prepare_excerpt( \WP_Post $item ): string {
        return html_entity_decode( wp_trim_excerpt( $item->post_excerpt, $item ) );
    }
}
