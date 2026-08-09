<?php
/**
 * Plugin Name: ST stock Sync
 * Description: Plugin to sync stock data with ST inventory soft
 * Version: 1.1.0
 * Author: Kael
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_D1_Inventory_Sync
{

    private $api_namespace = 'wc-d1-sync/v1';
    private $option_key = 'wc_d1_sync_api_key';
    private $option_exclude_cat = 'wc_d1_sync_exclude_cats';
    private $option_convert_box = 'wc_d1_sync_convert_box';
    private $option_lowest_stock = 'wc_d1_sync_lowest_stock';

    public function __construct()
    {
        // 1. Hook for admin settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // 2. Hook for REST API
        add_action('rest_api_init', array($this, 'register_api_routes'));

        // 3. Hook for product status changed
        add_action('woocommerce_new_product', array($this, 'schedule_json_generation'));
        add_action('woocommerce_update_product', array($this, 'schedule_json_generation'));
        add_action('woocommerce_trash_product', array($this, 'schedule_json_generation'));
        add_action('untrashed_post', array($this, 'schedule_json_generation_for_untrash'));

        // 4. Hook for WP-Cron
        add_action('wc_d1_sync_generate_json_event', array($this, 'generate_products_json'));

        // 5. hook for product page stock noticies
        add_action('woocommerce_after_add_to_cart_button', array($this, 'display_custom_stock_notices'));

        // Plugin activation
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
    }

    /**
     * Plugin activation
     */
    public function plugin_activation()
    {
        if (!get_option($this->option_key)) {
            $this->generate_new_api_key();
        }
        $this->schedule_json_generation();
    }

    /**
     * -------------------------------------------------------------------------
     * Backend settings
     * -------------------------------------------------------------------------
     */
    public function add_settings_page()
    {
        add_options_page(
            'ST stock Sync',
            'ST stock Sync',
            'manage_options',
            'wc-d1-inventory-sync',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting('wc_d1_sync_options', $this->option_exclude_cat);
        register_setting('wc_d1_sync_options', $this->option_convert_box);
        register_setting('wc_d1_sync_options', $this->option_lowest_stock);

        // API Request
        if (isset($_POST['regenerate_api_key']) && current_user_can('manage_options')) {
            check_admin_referer('regenerate_api_key_action', 'regenerate_api_key_nonce');
            $this->generate_new_api_key();
            add_settings_error('wc_d1_sync_messages', 'wc_d1_sync_message', 'API Key updated', 'updated');
        }

        // function to trigger JSON generating manually
        if (isset($_POST['generate_json_now']) && current_user_can('manage_options')) {
            check_admin_referer('generate_json_now_action', 'generate_json_now_nonce');
            $this->generate_products_json();
            add_settings_error('wc_d1_sync_messages', 'wc_d1_sync_message', 'JSON is generated', 'updated');
        }
    }

    private function generate_new_api_key()
    {
        $new_key = bin2hex(random_bytes(16)); // generate Key
        update_option($this->option_key, $new_key);
        return $new_key;
    }

    public function render_settings_page()
    {
        $api_key = get_option($this->option_key);
        $endpoint = rest_url($this->api_namespace . '/update-inventory');

        // get all product category
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        $excluded_cats = get_option($this->option_exclude_cat, array());
        $convert_box = get_option($this->option_convert_box, '');
        $lowest_stock = get_option($this->option_lowest_stock, '');

        $upload_dir = wp_upload_dir();
        $json_file_url = $upload_dir['baseurl'] . '/wc-d1-products.json';
        ?>
        <div class="wrap">
            <h1>Stock System Setting</h1>
            <?php settings_errors('wc_d1_sync_messages'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">API Endpoint</th>
                    <td>
                        <code><?php echo esc_url($endpoint); ?></code>
                        <p class="description">Please use this endpoint inside stock management system。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Key (Token)</th>
                    <td>
                        <code><?php echo esc_html($api_key); ?></code>
                        <p class="description">Put inside
                            header：<code>Authorization: Bearer <?php echo esc_html($api_key); ?></code></p>

                        <form method="post" action="" style="margin-top:10px;">
                            <?php wp_nonce_field('regenerate_api_key_action', 'regenerate_api_key_nonce'); ?>
                            <input type="submit" name="regenerate_api_key" class="button" value="Regenerate API Key"
                                onclick="return confirm('Warning: Old API Key will be disabled, do you still want to genereate new key？');">
                        </form>
                    </td>
                </tr>
                <tr>
                    <th scope="row">JSON Path</th>
                    <td>
                        <a href="<?php echo esc_url($json_file_url); ?>"
                            target="_blank"><?php echo esc_url($json_file_url); ?></a>
                        <p class="description">The JSON file will be auto-updated after product adding/deleting/editing.</p>
                        <form method="post" action="" style="margin-top:10px;">
                            <?php wp_nonce_field('generate_json_now_action', 'generate_json_now_nonce'); ?>
                            <input type="submit" name="generate_json_now" class="button"
                                value="Generate JSON immidimmediately.">
                        </form>
                    </td>
                </tr>
            </table>

            <hr>

            <form method="post" action="options.php">
                <?php settings_fields('wc_d1_sync_options'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Lowest selling stocks</th>
                        <td>
                            <input type="number" step="0.01" name="<?php echo esc_attr($this->option_lowest_stock); ?>"
                                value="<?php echo esc_attr($lowest_stock); ?>" class="regular-text">

                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Convert to box by step value</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->option_convert_box); ?>" value="1"
                                    <?php checked('1', $convert_box); ?>>
                                Enable stock conversion (divide stock by product's <code>glint_qty_step</code> value and round
                                down)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Exclude category</th>
                        <td>
                            <fieldset style="column-count: 4;">
                                <?php if (!empty($categories) && !is_wp_error($categories)): ?>
                                    <?php foreach ($categories as $category): ?>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr($this->option_exclude_cat); ?>[]"
                                                value="<?php echo esc_attr($category->term_id); ?>" <?php checked(in_array($category->term_id, $excluded_cats)); ?>>
                                            <?php echo esc_html($category->name); ?>
                                        </label><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>Can't find product category</p>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * -------------------------------------------------------------------------
     * REST API
     * -------------------------------------------------------------------------
     */
    public function register_api_routes()
    {
        register_rest_route($this->api_namespace, '/update-inventory', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_update_inventory'),
            'permission_callback' => array($this, 'api_permission_check'),
        ));
    }

    public function api_permission_check($request)
    {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header) {
            return new WP_Error('rest_unauthorized', '缺少 Authorization Header', array('status' => 401));
        }

        $saved_key = get_option($this->option_key);
        $token = str_replace('Bearer ', '', $auth_header);

        if (hash_equals($saved_key, trim($token))) {
            return true;
        }

        return new WP_Error('rest_forbidden', 'Invaid API Key ', array('status' => 403));
    }

    public function api_update_inventory($request)
    {
        $parameters = $request->get_json_params();

        $convert_box = get_option($this->option_convert_box, '');
        $lowest_stock_val = get_option($this->option_lowest_stock, '');
        $lowest_stock_threshold = ($lowest_stock_val !== '') ? floatval($lowest_stock_val) : null;

        // json format: [ {"id": 123, "stock": 10}, {"id": 456, "stock": 5} ]
        if (!is_array($parameters)) {
            return new WP_Error('invalid_data', 'Wrong array format', array('status' => 400));
        }

        $results = array();

        foreach ($parameters as $item) {
            if (!isset($item['id']) || !isset($item['stock'])) {
                continue;
            }

            $product_id = intval($item['id']);
            $stock_qty = floatval($item['stock']); // add float support

            // check backorder or force in stock
            $backorder = isset($item['backorder']) ? intval($item['backorder']) : 0;
            $force_in_stock = isset($item['force_in_stock']) ? intval($item['force_in_stock']) : 0;

            $product = wc_get_product($product_id);

            //save backorder or force in stock to database(default value is 0)
            if ($product && $product->is_type('simple')) {
                $product->update_meta_data('glint_backorder', $backorder);
                $product->update_meta_data('glint_force_in_stock', $force_in_stock);

                if ($convert_box == '1') {
                    global $wpdb;
                    $table_name = $wpdb->prefix . 'glint_product_qty';
                    $qty_step = floatval($wpdb->get_var($wpdb->prepare("SELECT glint_qty_step FROM {$table_name} WHERE post_id = %d", $product_id)));

                    if ($qty_step > 0 && $qty_step != 1) {
                        $stock_qty = floor($stock_qty / $qty_step);
                    }
                }

                if ($force_in_stock === 1){
                    $product->set_manage_stock(false); //Stop stock management
                    $product->set_stock_status('instock'); //Force in stock
                    $results[] = array(
                        'id' => $product_id,
                        'status' => 'success',
                        'msg' => 'Force in stock applied'
                    );
                }else{
                    if ($lowest_stock_threshold !== null && $stock_qty < $lowest_stock_threshold){
                        // if low stock
                        $product->set_manage_stock(false);
                        $product->set_stock_status('outofstock');
                        $results[] = array(
                            'id' => $product_id,
                            'status' => 'success',
                            'msg' => 'Low stock applied'
                        ); 
                    }else{
                        //if normal stock
                        $product->set_manage_stock(true); 
                        $product->set_stock_quantity($stock_qty);
                        $product->set_stock_status('instock');
                        $results[] = array(
                            'id' => $product_id,
                            'status' => 'success',
                            'msg' => 'In stock updated'
                        );
                    }
                }
                $product->save();
            } else {
                $results[] = array(
                    'id' => $product_id,
                    'status' => 'failed',
                    'reason' => 'No such product or it is not a simple product'
                );
            }
        }

        return rest_ensure_response(array(
            'message' => 'Stock update successfully',
            'data' => $results
        ));
    }

    /**
     * -------------------------------------------------------------------------
     * Frontend product display notices
     * -------------------------------------------------------------------------
     */
    public function display_custom_stock_notices()
    {
        global $product;

        if (!$product) {
            return;
        }

        $force_in_stock = intval($product->get_meta('glint_force_in_stock'));
        $backorder = intval($product->get_meta('glint_backorder'));

        if ($force_in_stock === 1) {
            echo '<div class="glint-stock-notice" style="margin-top: 15px; font-weight: 500;">Please contact us to confirm stock first</div>';
        } elseif ($backorder !== 0) {
            echo '<div class="glint-stock-notice" style="margin-top: 15px; font-weight: 500;">Please contact us for backorder</div>';
        }
    }

    /**
     * -------------------------------------------------------------------------
     * Async generate JSON file
     * -------------------------------------------------------------------------
     */
    public function schedule_json_generation_for_untrash($post_id)
    {
        if (get_post_type($post_id) === 'product') {
            $this->schedule_json_generation();
        }
    }

    public function schedule_json_generation($product_id = null)
    {
        // Use single async event. Add 5 seconds delay to prevent triggering too many times during bulk operations or quick saves
        if (!wp_next_scheduled('wc_d1_sync_generate_json_event')) {
            wp_schedule_single_event(time() + 5, 'wc_d1_sync_generate_json_event');
        }
    }

    public function generate_products_json()
    {
        $excluded_cats = get_option($this->option_exclude_cat, array());

        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish', // Only get published products
            'posts_per_page' => -1,        // Get all products
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_type',
                    'field' => 'slug',
                    'terms' => 'simple', // Only simple products
                ),
            ),
        );

        // Handle excluded categories
        if (!empty($excluded_cats)) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $excluded_cats,
                'operator' => 'NOT IN',
            );
        }

        $products_query = new WP_Query($query_args);
        $product_data = array();

        if ($products_query->have_posts()) {
            foreach ($products_query->posts as $post) {
                $product_data[] = array(
                    'id' => $post->ID,
                    'name' => $post->post_title,
                    'permalink' => get_permalink($post->ID),
                );
            }
        }

        // Save JSON file to wp-content/uploads directory
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/wc-d1-products.json';

        $json_content = wp_json_encode($product_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json_content) {
            // Use WP_Filesystem to be better, but for simple uploads directory writing, file_put_contents is stable enough
            file_put_contents($file_path, $json_content);
        }
    }
}

// Initialize plugin
new WC_D1_Inventory_Sync();
