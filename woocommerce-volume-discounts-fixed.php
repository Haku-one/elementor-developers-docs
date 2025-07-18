<?php
/**
 * Система скидок по объему для WooCommerce
 * Исправленная версия для вариативных товаров
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Проверяем активацию WooCommerce
if (!class_exists('WooCommerce')) {
    return;
}

/**
 * Основной класс системы скидок
 */
class WC_Volume_Discounts_Fixed {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Админка - только для родительских товаров
        add_action('woocommerce_product_options_pricing', array($this, 'add_volume_discount_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_volume_discount_fields'));
        add_action('admin_head', array($this, 'admin_styles'));
        
        // Фронтенд
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_volume_discounts'), 10, 1);
        add_filter('woocommerce_get_item_data', array($this, 'display_discount_in_cart'), 10, 2);
        add_filter('woocommerce_cart_item_name', array($this, 'display_discount_in_mini_cart'), 10, 3);
        
        // Форматирование цен
        add_filter('wc_price', array($this, 'format_price'), 10, 1);
        add_filter('woocommerce_get_price_html', array($this, 'format_price'), 100, 1);
        add_filter('woocommerce_cart_item_price', array($this, 'format_price'), 100, 1);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'format_price'), 100, 1);
        
        // AJAX
        add_action('wp_ajax_get_volume_discount_info', array($this, 'ajax_get_discount_info'));
        add_action('wp_ajax_nopriv_get_volume_discount_info', array($this, 'ajax_get_discount_info'));
        add_action('wp_ajax_add_to_cart_volume_discount', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_add_to_cart_volume_discount', array($this, 'ajax_add_to_cart'));
        
        // Шорткод
        add_shortcode('volume_discount_controls', array($this, 'volume_discount_shortcode'));
        
        // Скрипты
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Обновление мини-корзины
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'update_mini_cart_fragments'));
    }
    
    /**
     * Получение настроек скидок для товара (всегда для родительского)
     */
    public function get_discount_settings($product_id) {
        $discount_settings = array();
        
        // Для вариаций ВСЕГДА используем настройки родительского товара
        $parent_id = wp_get_post_parent_id($product_id);
        if ($parent_id) {
            $product_id = $parent_id;
        }
        
        // Получаем настройки из метаполей родительского товара
        for ($i = 1; $i <= 7; $i++) {
            $min = get_post_meta($product_id, '_volume_discount_min_' . $i, true);
            $max = ($i == 7) ? 999999 : get_post_meta($product_id, '_volume_discount_max_' . $i, true);
            $percentage = get_post_meta($product_id, '_volume_discount_percentage_' . $i, true);
            
            if (!empty($min) && !empty($percentage)) {
                $discount_settings[] = array(
                    'min' => intval($min),
                    'max' => intval($max),
                    'percentage' => floatval(str_replace(',', '.', $percentage))
                );
            }
        }
        
        // Значения по умолчанию
        if (empty($discount_settings)) {
            $discount_settings = array(
                array('min' => 5, 'max' => 10, 'percentage' => 3),
                array('min' => 11, 'max' => 20, 'percentage' => 6),
                array('min' => 21, 'max' => 59, 'percentage' => 12.5),
                array('min' => 60, 'max' => 119, 'percentage' => 45),
                array('min' => 120, 'max' => 239, 'percentage' => 46.5),
                array('min' => 240, 'max' => 799, 'percentage' => 48),
                array('min' => 800, 'max' => 999999, 'percentage' => 50)
            );
        }
        
        return $discount_settings;
    }
    
    /**
     * Расчет процента скидки
     */
    public function get_discount_percentage($quantity, $product_id) {
        $discount_settings = $this->get_discount_settings($product_id);
        
        foreach ($discount_settings as $setting) {
            if ($quantity >= $setting['min'] && $quantity <= $setting['max']) {
                return floatval($setting['percentage']);
            }
        }
        
        return 0;
    }
    
    /**
     * Применение скидок в корзине
     */
    public function apply_volume_discounts($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (did_action('woocommerce_before_calculate_totals') >= 2) return;
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (!isset($cart_item['quantity'])) continue;
            
            $quantity = $cart_item['quantity'];
            $product_id = $cart_item['product_id'];
            $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
            
            // Используем родительский ID для получения настроек скидок
            $discount_product_id = $variation_id ? $product_id : $product_id;
            $discount_percentage = $this->get_discount_percentage($quantity, $discount_product_id);
            
            if ($discount_percentage > 0) {
                $original_price = $cart_item['data']->get_regular_price();
                if (empty($original_price)) {
                    $original_price = $cart_item['data']->get_price();
                }
                
                if ($original_price) {
                    $discounted_price = $original_price * (1 - ($discount_percentage / 100));
                    $cart_item['data']->set_price($discounted_price);
                    
                    // Сохраняем информацию о скидке
                    $cart->cart_contents[$cart_item_key]['volume_discount'] = $discount_percentage;
                    $cart->cart_contents[$cart_item_key]['original_price'] = $original_price;
                }
            }
        }
    }
    
    /**
     * Форматирование цены
     */
    public function format_price($price_html) {
        $price_html = preg_replace('/(\d+)\.00/', '$1', $price_html);
        $price_html = str_replace('₽', ' руб.', $price_html);
        return $price_html;
    }
    
    /**
     * Отображение скидки в корзине
     */
    public function display_discount_in_cart($cart_item_data, $cart_item) {
        if (isset($cart_item['volume_discount']) && $cart_item['volume_discount'] > 0) {
            $cart_item_data[] = array(
                'name' => 'Скидка',
                'value' => $cart_item['volume_discount'] . '%',
                'display' => '',
            );
        }
        return $cart_item_data;
    }
    
    /**
     * Отображение скидки в мини-корзине
     */
    public function display_discount_in_mini_cart($product_name, $cart_item, $cart_item_key) {
        if (isset($cart_item['volume_discount']) && $cart_item['volume_discount'] > 0) {
            if (strpos($product_name, 'volume-discount-mini') === false) {
                $product_name .= '<div class="volume-discount-mini" style="font-size: 12px; color: green;">Скидка: ' . $cart_item['volume_discount'] . '%</div>';
            }
        }
        return $product_name;
    }
    
    /**
     * Обновление фрагментов мини-корзины
     */
    public function update_mini_cart_fragments($fragments) {
        // Обновляем счетчик товаров
        ob_start();
        wc_cart_totals_subtotal_html();
        $fragments['.cart-subtotal .woocommerce-Price-amount'] = ob_get_clean();
        
        // Обновляем общее количество
        $fragments['.cart-contents-count'] = WC()->cart->get_cart_contents_count();
        
        return $fragments;
    }
    
    /**
     * AJAX получение информации о скидке
     */
    public function ajax_get_discount_info() {
        check_ajax_referer('volume_discount_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        
        if ($product_id <= 0 || $quantity <= 0) {
            wp_send_json_error('Неверные параметры');
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Товар не найден');
        }
        
        // Для вариаций используем родительский ID для настроек скидок
        $parent_id = $product->get_parent_id();
        $discount_product_id = $parent_id ? $parent_id : $product_id;
        
        $discount_percentage = $this->get_discount_percentage($quantity, $discount_product_id);
        
        $html = '';
        if ($discount_percentage > 0) {
            $regular_price = $product->get_regular_price();
            if (empty($regular_price)) {
                $regular_price = $product->get_price();
            }
            
            $discounted_price = $regular_price * (1 - ($discount_percentage / 100));
            $savings = ($regular_price - $discounted_price) * $quantity;
            
            $html = '<div class="volume-discount-info">';
            $html .= '<p>Скидка: <strong>' . $discount_percentage . '%</strong></p>';
            $html .= '<p>Цена со скидкой: <strong>' . number_format($discounted_price, 0, '.', ' ') . ' руб.</strong></p>';
            $html .= '<p>Экономия: <strong>' . number_format($savings, 0, '.', ' ') . ' руб.</strong></p>';
            $html .= '</div>';
        }
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX добавление в корзину
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('volume_discount_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        
        if ($product_id <= 0 || $quantity <= 0) {
            wp_send_json_error('Неверные параметры');
        }
        
        // Собираем атрибуты вариации
        $variation_data = array();
        if ($variation_id > 0) {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'attribute_') === 0) {
                    $variation_data[$key] = sanitize_text_field($value);
                }
            }
        }
        
        // Добавляем в корзину
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data);
        
        if ($cart_item_key) {
            // Получаем обновленные фрагменты
            $fragments = apply_filters('woocommerce_add_to_cart_fragments', array(
                '.widget_shopping_cart_content' => $this->get_mini_cart_content(),
                '.cart-contents-count' => WC()->cart->get_cart_contents_count(),
                '.cart-subtotal .woocommerce-Price-amount' => wc_price(WC()->cart->get_subtotal())
            ));
            
            wp_send_json_success(array(
                'message' => 'Товар добавлен в корзину',
                'cart_hash' => WC()->cart->get_cart_hash(),
                'fragments' => $fragments
            ));
        } else {
            wp_send_json_error('Не удалось добавить товар в корзину');
        }
    }
    
    /**
     * Получение содержимого мини-корзины
     */
    private function get_mini_cart_content() {
        ob_start();
        woocommerce_mini_cart();
        return '<div class="widget_shopping_cart_content">' . ob_get_clean() . '</div>';
    }
    
    /**
     * Подключение скриптов
     */
    public function enqueue_scripts() {
        if (is_product() || is_shop() || is_product_category() || is_cart()) {
            wp_enqueue_script('jquery');
            wp_localize_script('jquery', 'volume_discount_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('volume_discount_nonce'),
                'cart_url' => wc_get_cart_url()
            ));
        }
    }
    
    /**
     * Шорткод элементов управления
     */
    public function volume_discount_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'button_text' => 'Добавить в корзину',
            'button_class' => 'button',
            'show_price' => 'yes',
            'show_discounts' => 'yes',
        ), $atts);
        
        if (empty($atts['product_id'])) {
            global $product;
            if (!$product) return '<p>Товар не найден.</p>';
            $product_id = $product->get_id();
            $product_obj = $product;
        } else {
            $product_id = intval($atts['product_id']);
            $product_obj = wc_get_product($product_id);
            if (!$product_obj) return '<p>Товар не найден.</p>';
        }
        
        $is_variable = $product_obj->is_type('variable');
        $unique_id = 'volume-controls-' . $product_id . '-' . rand(1000, 9999);
        
        // Для вариативных товаров берем настройки скидок с родительского товара
        $discount_product_id = $is_variable ? $product_id : $product_id;
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr($unique_id); ?>" class="volume-discount-controls" data-product-id="<?php echo esc_attr($product_id); ?>">
            <?php if ($atts['show_price'] === 'yes' && !$is_variable): ?>
                <div class="product-price">
                    <span class="price"><?php echo wc_price($product_obj->get_price()); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($is_variable): ?>
                <?php $this->render_variable_product_form($product_obj, $atts, $unique_id); ?>
            <?php else: ?>
                <?php $this->render_simple_product_form($product_obj, $atts, $unique_id); ?>
            <?php endif; ?>
            
            <div class="volume-discount-info-container"></div>
            
            <?php if ($atts['show_discounts'] === 'yes'): ?>
                <div class="volume-discount-table">
                    <p>Скидки по количеству:</p>
                    <ul>
                        <?php foreach ($this->get_discount_settings($discount_product_id) as $setting): ?>
                            <li>
                                <?php if ($setting['max'] < 999999): ?>
                                    От <?php echo $setting['min']; ?> до <?php echo $setting['max']; ?> шт: <?php echo $setting['percentage']; ?>%
                                <?php else: ?>
                                    От <?php echo $setting['min']; ?> шт: <?php echo $setting['percentage']; ?>%
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="cart-notification"></div>
        </div>
        
        <style>
        #<?php echo esc_attr($unique_id); ?> {
            margin: 20px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .quantity {
            display: flex;
            align-items: center;
        }
        #<?php echo esc_attr($unique_id); ?> .quantity button {
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            background: #f9f9f9;
            cursor: pointer;
        }
        #<?php echo esc_attr($unique_id); ?> .quantity input {
            width: 60px;
            height: 30px;
            text-align: center;
            border: 1px solid #ddd;
            margin: 0 5px;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-info-container {
            margin: 15px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-info {
            background: #f8f8f8;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-info p {
            margin: 5px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-table {
            margin: 20px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-table ul {
            list-style: none;
            padding: 0;
        }
        #<?php echo esc_attr($unique_id); ?> .volume-discount-table li {
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        #<?php echo esc_attr($unique_id); ?> .variations {
            margin: 15px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .variation-row {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .variation-row label {
            width: 100px;
            display: block;
        }
        #<?php echo esc_attr($unique_id); ?> .variation-row select {
            flex: 1;
            max-width: 200px;
        }
        #<?php echo esc_attr($unique_id); ?> .cart-notification {
            margin: 15px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .success {
            color: green;
            background: #f0fff0;
            padding: 10px;
            border: 1px solid #d0e9c6;
        }
        #<?php echo esc_attr($unique_id); ?> .error {
            color: red;
            background: #fff0f0;
            padding: 10px;
            border: 1px solid #ebccd1;
        }
        #<?php echo esc_attr($unique_id); ?> .single_variation_wrap {
            margin: 15px 0;
        }
        #<?php echo esc_attr($unique_id); ?> .woocommerce-variation-price {
            margin: 10px 0;
            font-weight: bold;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var container = $('#<?php echo esc_js($unique_id); ?>');
            var productId = <?php echo esc_js($product_id); ?>;
            var isVariable = <?php echo esc_js($is_variable ? 'true' : 'false'); ?>;
            
            // Обновление информации о скидке
            function updateDiscountInfo() {
                var quantity = parseInt(container.find('.qty').val()) || 1;
                var currentProductId = productId;
                
                if (isVariable) {
                    var variationId = container.find('input[name="variation_id"]').val();
                    if (!variationId || variationId == 0) {
                        container.find('.volume-discount-info-container').html('');
                        return;
                    }
                    currentProductId = variationId;
                }
                
                $.post(volume_discount_ajax.ajax_url, {
                    action: 'get_volume_discount_info',
                    nonce: volume_discount_ajax.nonce,
                    product_id: currentProductId,
                    quantity: quantity
                }, function(response) {
                    if (response.success) {
                        container.find('.volume-discount-info-container').html(response.data.html);
                    } else {
                        container.find('.volume-discount-info-container').html('');
                    }
                });
            }
            
            // Кнопки количества
            container.on('click', '.qty-plus', function() {
                var input = container.find('.qty');
                var val = parseInt(input.val()) + 1;
                input.val(val).trigger('change');
            });
            
            container.on('click', '.qty-minus', function() {
                var input = container.find('.qty');
                var val = parseInt(input.val()) - 1;
                if (val < 1) val = 1;
                input.val(val).trigger('change');
            });
            
            // Изменение количества
            container.on('change', '.qty', function() {
                updateDiscountInfo();
            });
            
            // Изменение вариации
            if (isVariable) {
                container.on('change', 'select[name^="attribute_"]', function() {
                    setTimeout(updateDiscountInfo, 200);
                });
            }
            
            // Добавление в корзину
            container.on('click', '.add-to-cart-btn', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var quantity = parseInt(container.find('.qty').val()) || 1;
                var data = {
                    action: 'add_to_cart_volume_discount',
                    nonce: volume_discount_ajax.nonce,
                    product_id: productId,
                    quantity: quantity
                };
                
                if (isVariable) {
                    var variationId = container.find('input[name="variation_id"]').val();
                    if (!variationId || variationId == 0) {
                        container.find('.cart-notification').html('<div class="error">Выберите опции товара</div>');
                        return;
                    }
                    data.variation_id = variationId;
                    
                    // Добавляем атрибуты
                    container.find('select[name^="attribute_"]').each(function() {
                        data[$(this).attr('name')] = $(this).val();
                    });
                }
                
                button.prop('disabled', true).text('Добавление...');
                
                $.post(volume_discount_ajax.ajax_url, data, function(response) {
                    button.prop('disabled', false).text('<?php echo esc_js($atts['button_text']); ?>');
                    
                    if (response.success) {
                        container.find('.cart-notification').html('<div class="success">' + response.data.message + '</div>');
                        
                        // Обновляем фрагменты корзины
                        if (response.data.fragments) {
                            $.each(response.data.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                        }
                        
                        // Триггерим обновление корзины
                        $(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash, button]);
                        $(document.body).trigger('wc_fragments_refreshed');
                    } else {
                        container.find('.cart-notification').html('<div class="error">' + response.data + '</div>');
                    }
                    
                    setTimeout(function() {
                        container.find('.cart-notification').html('');
                    }, 5000);
                });
            });
            
            // Инициализация
            updateDiscountInfo();
        });
        </script>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Рендер формы для простого товара
     */
    private function render_simple_product_form($product, $atts, $unique_id) {
        ?>
        <div class="quantity-controls">
            <div class="quantity">
                <button type="button" class="qty-minus">-</button>
                <input type="number" class="qty" value="1" min="1" step="1">
                <button type="button" class="qty-plus">+</button>
            </div>
            <button type="button" class="add-to-cart-btn <?php echo esc_attr($atts['button_class']); ?>">
                <?php echo esc_html($atts['button_text']); ?>
            </button>
        </div>
        <?php
    }
    
    /**
     * Рендер формы для вариативного товара
     */
    private function render_variable_product_form($product, $atts, $unique_id) {
        $available_variations = $product->get_available_variations();
        $attributes = $product->get_variation_attributes();
        ?>
        <div class="variations">
            <?php foreach ($attributes as $attribute_name => $options): ?>
                <div class="variation-row">
                    <label for="<?php echo esc_attr(sanitize_title($attribute_name)); ?>">
                        <?php echo wc_attribute_label($attribute_name); ?>:
                    </label>
                    <select name="attribute_<?php echo esc_attr(sanitize_title($attribute_name)); ?>" id="<?php echo esc_attr(sanitize_title($attribute_name)); ?>">
                        <option value="">Выберите <?php echo wc_attribute_label($attribute_name); ?></option>
                        <?php foreach ($options as $option): ?>
                            <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="single_variation_wrap" style="display: none;">
            <div class="single_variation"></div>
            <div class="quantity-controls">
                <div class="quantity">
                    <button type="button" class="qty-minus">-</button>
                    <input type="number" class="qty" value="1" min="1" step="1">
                    <button type="button" class="qty-plus">+</button>
                </div>
                <button type="button" class="add-to-cart-btn <?php echo esc_attr($atts['button_class']); ?>">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
            </div>
        </div>
        
        <input type="hidden" name="variation_id" value="0">
        
                 <script>
         jQuery(document).ready(function($) {
             var variations = <?php echo wp_json_encode($available_variations); ?>;
             var container = $('#<?php echo esc_js($unique_id); ?>');
             
             // Отладка: выводим доступные вариации в консоль
             console.log('Available variations:', variations);
             
             function findVariation() {
                var attributes = {};
                var allSelected = true;
                
                                                  container.find('select[name^="attribute_"]').each(function() {
                     var name = $(this).attr('name').replace('attribute_', '');
                     var value = $(this).val();
                     
                     if (value === '') {
                         allSelected = false;
                     }
                     attributes[name] = value;
                 });
                 
                 console.log('Selected attributes:', attributes);
                 
                 if (!allSelected) {
                     container.find('.single_variation_wrap').hide();
                     container.find('input[name="variation_id"]').val(0);
                     return;
                 }
                 
                 var matchedVariation = null;
                 $.each(variations, function(index, variation) {
                     var match = true;
                     console.log('Checking variation:', variation);
                     
                     $.each(attributes, function(attr_name, attr_value) {
                         if (variation.attributes[attr_name] && variation.attributes[attr_name] !== attr_value) {
                             match = false;
                             return false;
                         }
                     });
                     
                     if (match) {
                         matchedVariation = variation;
                         console.log('Found matching variation:', matchedVariation);
                         return false;
                     }
                 });
                
                if (matchedVariation) {
                    container.find('input[name="variation_id"]').val(matchedVariation.variation_id);
                    
                                         // Правильно форматируем цену вариации
                     var priceHtml = '';
                     if (matchedVariation.price_html) {
                         priceHtml = matchedVariation.price_html;
                     } else if (matchedVariation.display_price) {
                         priceHtml = '<span class="woocommerce-Price-amount amount"><bdi>' + Math.round(matchedVariation.display_price) + ' руб.</bdi></span>';
                     } else if (matchedVariation.display_regular_price) {
                         priceHtml = '<span class="woocommerce-Price-amount amount"><bdi>' + Math.round(matchedVariation.display_regular_price) + ' руб.</bdi></span>';
                     }
                     
                     console.log('Price HTML:', priceHtml);
                     
                     if (priceHtml) {
                         container.find('.single_variation').html('<div class="woocommerce-variation-price"><span class="price">' + priceHtml + '</span></div>');
                     } else {
                         container.find('.single_variation').html('<div class="woocommerce-variation-price">Цена не найдена</div>');
                     }
                    
                    container.find('.single_variation_wrap').show();
                    
                    // Обновляем информацию о скидке для новой вариации
                    setTimeout(function() {
                        updateDiscountInfo();
                    }, 100);
                } else {
                    container.find('.single_variation_wrap').hide();
                    container.find('input[name="variation_id"]').val(0);
                    container.find('.volume-discount-info-container').html('');
                }
            }
            
            container.on('change', 'select[name^="attribute_"]', function() {
                findVariation();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Добавление полей в админке товара (ТОЛЬКО для родительских товаров)
     */
    public function add_volume_discount_fields() {
        global $post;
        
        if (!isset($post->ID) || get_post_type($post->ID) !== 'product') return;
        
        $product = wc_get_product($post->ID);
        if (!$product) return;
        
        // Показываем поля только для простых товаров и вариативных (родительских)
        if (!$product->is_type('simple') && !$product->is_type('variable')) return;
        
        echo '<div class="options_group">';
        echo '<h3>Настройки скидок по объему</h3>';
        if ($product->is_type('variable')) {
            echo '<p>Эти настройки применяются ко всем вариациям данного товара</p>';
        }
        echo '<p>Укажите диапазоны количества товаров и соответствующие проценты скидок (до 7 правил)</p>';
        
        for ($i = 1; $i <= 7; $i++) {
            echo '<div class="volume-discount-field-group">';
            
            woocommerce_wp_text_input(array(
                'id' => '_volume_discount_min_' . $i,
                'label' => 'От (шт)',
                'type' => 'number',
                'custom_attributes' => array('step' => '1', 'min' => '1'),
                'wrapper_class' => 'form-field'
            ));
            
            if ($i < 7) {
                woocommerce_wp_text_input(array(
                    'id' => '_volume_discount_max_' . $i,
                    'label' => 'До (шт)',
                    'type' => 'number',
                    'custom_attributes' => array('step' => '1', 'min' => '1'),
                    'wrapper_class' => 'form-field'
                ));
            }
            
            woocommerce_wp_text_input(array(
                'id' => '_volume_discount_percentage_' . $i,
                'label' => 'Скидка (%)',
                'type' => 'text',
                'wrapper_class' => 'form-field'
            ));
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Сохранение полей товара
     */
    public function save_volume_discount_fields($post_id) {
        if (get_post_type($post_id) !== 'product') return;
        
        for ($i = 1; $i <= 7; $i++) {
            $min_field = '_volume_discount_min_' . $i;
            $min_value = isset($_POST[$min_field]) ? wc_clean($_POST[$min_field]) : '';
            update_post_meta($post_id, $min_field, $min_value);
            
            if ($i < 7) {
                $max_field = '_volume_discount_max_' . $i;
                $max_value = isset($_POST[$max_field]) ? wc_clean($_POST[$max_field]) : '';
                update_post_meta($post_id, $max_field, $max_value);
            }
            
            $percentage_field = '_volume_discount_percentage_' . $i;
            $percentage_value = isset($_POST[$percentage_field]) ? wc_clean($_POST[$percentage_field]) : '';
            $percentage_value = str_replace(',', '.', $percentage_value);
            update_post_meta($post_id, $percentage_field, $percentage_value);
        }
    }
    
    /**
     * Стили для админки
     */
    public function admin_styles() {
        ?>
        <style>
        .volume-discount-field-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .volume-discount-field-group .form-field {
            margin: 0 10px 0 0 !important;
            width: 120px;
        }
        </style>
        <?php
    }
}

// Инициализация
new WC_Volume_Discounts_Fixed();