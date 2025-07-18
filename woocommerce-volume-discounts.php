<?php
/**
 * Система скидок по объему для WooCommerce
 * Поддерживает простые и вариативные товары
 * Все функции включены в один файл без внешних зависимостей
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Функция для получения настроек скидок для товара
function get_product_volume_discount_settings($product_id) {
    $discount_settings = array();
    
    // Для вариативных товаров получаем родительский ID
    $parent_id = wp_get_post_parent_id($product_id);
    if ($parent_id) {
        $product_id = $parent_id; // Используем настройки родительского товара для вариаций
    }
    
    // Получаем все настройки для данного товара
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
    
    // Если настройки не заданы, используем значения по умолчанию
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

// Функция для расчета процента скидки на основе количества и ID товара
function get_volume_discount_percentage($quantity, $product_id = 0) {
    if ($product_id > 0) {
        $discount_settings = get_product_volume_discount_settings($product_id);
        
        foreach ($discount_settings as $setting) {
            if ($quantity >= $setting['min'] && $quantity <= $setting['max']) {
                return floatval($setting['percentage']);
            }
        }
    } else {
        // Розничные скидки
        if ($quantity >= 5 && $quantity <= 10) return 3;
        elseif ($quantity >= 11 && $quantity <= 20) return 6;
        elseif ($quantity >= 21 && $quantity < 60) return 12.5;
        // Оптовые скидки
        elseif ($quantity >= 60 && $quantity < 120) return 45;
        elseif ($quantity >= 120 && $quantity < 240) return 46.5;
        elseif ($quantity >= 240 && $quantity < 800) return 48;
        elseif ($quantity >= 800) return 50;
    }
    
    return 0; // Нет скидки
}

// Функция для форматирования цены без нулей и с "руб." вместо символа рубля
function format_price_without_zeros($price_html) {
    $price_html = preg_replace('/(\d+)\.00/', '$1', $price_html);
    $price_html = str_replace('₽', ' руб.', $price_html);
    return $price_html;
}

// Применяем фильтр форматирования цены
add_filter('wc_price', 'format_price_without_zeros', 10, 1);
add_filter('woocommerce_get_price_html', 'format_price_without_zeros', 100, 1);
add_filter('woocommerce_cart_item_price', 'format_price_without_zeros', 100, 1);
add_filter('woocommerce_cart_item_subtotal', 'format_price_without_zeros', 100, 1);

// Применение скидок в корзине
function apply_volume_discounts_to_cart_items($cart_object) {
    if (is_admin() && !defined('DOING_AJAX') || did_action('woocommerce_before_calculate_totals') >= 2) {
        return;
    }
    
    foreach ($cart_object->get_cart() as $cart_item_key => $cart_item) {
        if (!isset($cart_item['quantity'])) continue;
        
        $quantity = $cart_item['quantity'];
        $product_id = $cart_item['product_id'];
        $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
        $actual_id = $variation_id ? $variation_id : $product_id;
        
        $discount_percentage = get_volume_discount_percentage($quantity, $actual_id);
        
        if ($discount_percentage > 0 && isset($cart_item['data'])) {
            // Получаем регулярную цену товара
            $original_price = $cart_item['data']->get_regular_price();
            
            // Если регулярная цена не задана, используем текущую цену
            if (empty($original_price)) {
                $original_price = $cart_item['data']->get_price();
            }
            
            if ($original_price) {
                // Вычисляем цену со скидкой
                $discounted_price = $original_price * (1 - ($discount_percentage / 100));
                
                // Устанавливаем новую цену для товара в корзине
                $cart_item['data']->set_price($discounted_price);
                
                // Сохраняем информацию о скидке и оригинальной цене
                $cart_object->cart_contents[$cart_item_key]['volume_discount'] = $discount_percentage;
                $cart_object->cart_contents[$cart_item_key]['original_price'] = $original_price;
            }
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'apply_volume_discounts_to_cart_items', 10, 1);

// Отображение скидки в корзине и мини-корзине
function display_volume_discount_in_cart($cart_item_data, $cart_item) {
    if (isset($cart_item['volume_discount']) && $cart_item['volume_discount'] > 0) {
        $cart_item_data[] = array(
            'name' => 'Скидка',
            'value' => $cart_item['volume_discount'] . '%',
            'display' => '',
        );
    }
    return $cart_item_data;
}
add_filter('woocommerce_get_item_data', 'display_volume_discount_in_cart', 10, 2);

function display_discount_in_mini_cart($product_name, $cart_item, $cart_item_key) {
    if (isset($cart_item['volume_discount']) && $cart_item['volume_discount'] > 0) {
        if (strpos($product_name, 'mini-cart-discount') === false) {
            $product_name .= '<div class="mini-cart-discount">Скидка: ' . $cart_item['volume_discount'] . '%</div>';
        }
    }
    return $product_name;
}
add_filter('woocommerce_cart_item_name', 'display_discount_in_mini_cart', 10, 3);

// Исправляем проблему с форматированием цены в мини-корзине
function fix_mini_cart_prices() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        function fixMiniCartPrices() {
            $('.elementor-menu-cart__products .product-price').each(function() {
                var html = $(this).html();
                html = html.replace(/(\d+)\s+(\d+)(\s+руб\.)/, '$1$3');
                $(this).html(html);
            });
            
            $('.elementor-menu-cart__subtotal').each(function() {
                var html = $(this).html();
                html = html.replace(/(\d+)(\d+)+(\s+руб\.)/, '$1$3');
                $(this).html(html);
            });
        }
        
        $(document.body).on('wc_fragments_refreshed added_to_cart', function() {
            setTimeout(fixMiniCartPrices, 100);
        });
        
        $(document).on('click', '.elementor-menu-cart__toggle_button', function() {
            setTimeout(fixMiniCartPrices, 300);
        });
        
        setTimeout(fixMiniCartPrices, 500);
    });
    </script>
    <?php
}
add_action('wp_footer', 'fix_mini_cart_prices', 999);

// Заменяем HTML подытога в мини-корзине
function replace_mini_cart_subtotal($fragments) {
    if (isset($fragments['div.elementor-menu-cart__subtotal'])) {
        $subtotal = WC()->cart->get_subtotal();
        $formatted_subtotal = number_format($subtotal, 0, '.', '') . ' руб.';
        $fragments['div.elementor-menu-cart__subtotal'] = '<div class="elementor-menu-cart__subtotal"><strong>Подытог:</strong> <span class="woocommerce-Price-amount amount"><bdi>' . $formatted_subtotal . '</bdi></span></div>';
    }
    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'replace_mini_cart_subtotal', 999);

// AJAX обработчик для получения информации о скидке
function get_volume_discount_info_ajax() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    if ($product_id <= 0) {
        wp_send_json_error(array('message' => 'Неверный ID товара'));
        return;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Товар не найден'));
        return;
    }
    
    $discount_percentage = get_volume_discount_percentage($quantity, $product_id);
    
    // Получаем цену товара (учитываем, что это может быть вариация)
    $price = $product->get_price();
    $regular_price = $product->get_regular_price();
    
    // Если регулярная цена не задана, используем обычную цену
    if (empty($regular_price)) {
        $regular_price = $price;
    }
    
    $html = '';
    if ($discount_percentage > 0) {
        $discounted_price = $regular_price * (1 - ($discount_percentage / 100));
        $savings = $regular_price - $discounted_price;
        
        $html .= '<div class="discount-info">';
        $html .= '<p>Скидка: <strong>' . $discount_percentage . '%</strong></p>';
        $html .= '<p>Цена со скидкой: <strong>' . number_format($discounted_price, 0, '.', '') . ' руб.</strong></p>';
        $html .= '<p>Экономия: <strong>' . number_format($savings * $quantity, 0, '.', '') . ' руб.</strong></p>';
        $html .= '</div>';
    }
    
    wp_send_json_success(array('html' => $html));
}
add_action('wp_ajax_get_volume_discount_info', 'get_volume_discount_info_ajax');
add_action('wp_ajax_nopriv_get_volume_discount_info', 'get_volume_discount_info_ajax');

// AJAX обработчик для добавления товара в корзину
function add_to_cart_with_volume_discount_ajax() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    if ($product_id <= 0) {
        wp_send_json_error(array('message' => 'Неверный ID товара'));
        return;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => 'Товар не найден'));
        return;
    }
    
    $cart_item_key = '';
    
    if ($product->is_type('variable') && $variation_id > 0) {
        // Получаем атрибуты вариации
        $variation_data = array();
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'attribute_') === 0) {
                $variation_data[$key] = sanitize_text_field($value);
            }
        }
        
        if (empty($variation_data) && $variation_id > 0) {
            // Если атрибуты не переданы, но указан ID вариации, получаем атрибуты из вариации
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $variation_attributes = $variation->get_attributes();
                foreach ($variation_attributes as $attr_name => $attr_value) {
                    $variation_data['attribute_' . $attr_name] = $attr_value;
                }
            }
        }
        
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data);
    } else {
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
    }
    
    if (!$cart_item_key) {
        wp_send_json_error(array('message' => 'Не удалось добавить товар в корзину'));
        return;
    }
    
    // Получаем обновленные фрагменты корзины
    ob_start();
    woocommerce_mini_cart();
    $mini_cart = ob_get_clean();
    
    $fragments = array(
        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
        '.elementor-menu-cart__wrapper' => $mini_cart
    );
    
    wp_send_json_success(array(
        'message' => 'Товар добавлен в корзину',
        'fragments' => $fragments
    ));
}
add_action('wp_ajax_add_to_cart_with_volume_discount', 'add_to_cart_with_volume_discount_ajax');
add_action('wp_ajax_nopriv_add_to_cart_with_volume_discount', 'add_to_cart_with_volume_discount_ajax');

// Универсальный шорткод для вывода элементов управления количеством товара
function volume_discount_controls_shortcode($atts) {
    if (!function_exists('wc_get_product')) return '<p>WooCommerce не активен.</p>';
    
    $atts = shortcode_atts(array(
        'product_id' => 0,
        'button_text' => 'Добавить в корзину',
        'button_class' => 'elementor-button',
        'show_price' => 'yes',
        'show_discounts' => 'yes',
    ), $atts);
    
    if (empty($atts['product_id']) || $atts['product_id'] == 0) {
        global $product;
        if (!$product) return '<p>Товар не найден.</p>';
        $product_id = $product->get_id();
        $product = wc_get_product($product_id);
    } else {
        $product_id = intval($atts['product_id']);
        $product = wc_get_product($product_id);
        if (!$product) return '<p>Товар с ID ' . $product_id . ' не найден.</p>';
    }
    
    $is_variable = $product->is_type('variable');
    $price = $product->get_price();
    $regular_price = $product->get_regular_price();
    if (empty($regular_price)) $regular_price = $price;
    
    $discount_settings = get_product_volume_discount_settings($product_id);
    $unique_id = 'volume-discount-' . $product_id . '-' . rand(1000, 9999);
    
    // Подключаем jQuery если не подключен
    wp_enqueue_script('jquery');
    
    ob_start();
    ?>
    <div id="<?php echo esc_attr($unique_id); ?>" class="volume-discount-controls" data-product-id="<?php echo esc_attr($product_id); ?>">
        <?php if ($atts['show_price'] === 'yes' && !$is_variable): ?>
        <div class="product-price">
            <span class="price"><?php echo wc_price($price, array('decimals' => 0)); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($is_variable): ?>
        <form class="variations_form cart" method="post" enctype="multipart/form-data" data-product_id="<?php echo esc_attr($product_id); ?>">
            <?php 
            $available_variations = $product->get_available_variations();
            $attributes = $product->get_variation_attributes();
            ?>
            <div class="variations">
                <?php foreach ($attributes as $attribute_name => $options): ?>
                <div class="variation-row">
                    <div class="label">
                        <label for="<?php echo esc_attr(sanitize_title($attribute_name)); ?>">
                            <?php echo wc_attribute_label($attribute_name); ?>
                        </label>
                    </div>
                    <div class="value">
                        <?php
                        $selected = isset($_REQUEST['attribute_' . sanitize_title($attribute_name)]) 
                            ? wc_clean(stripslashes(urldecode($_REQUEST['attribute_' . sanitize_title($attribute_name)]))) 
                            : $product->get_variation_default_attribute($attribute_name);
                        
                        wc_dropdown_variation_attribute_options(array(
                            'options'   => $options,
                            'attribute' => $attribute_name,
                            'product'   => $product,
                            'selected'  => $selected,
                        ));
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="reset-variations-wrapper">
                    <a class="reset_variations" href="#" style="visibility: hidden;">Очистить</a>
                </div>
            </div>
            
            <div class="single_variation_wrap">
                <div class="woocommerce-variation single_variation"></div>
                <div class="woocommerce-variation-add-to-cart variations_button">
                    <div class="quantity-controls">
                        <div class="quantity">
                            <button type="button" class="minus">-</button>
                            <input type="number" class="qty" name="quantity" value="1" min="1" step="1">
                            <button type="button" class="plus">+</button>
                        </div>
                        <button type="submit" class="single_add_to_cart_button <?php echo esc_attr($atts['button_class']); ?>" disabled><?php echo esc_html($atts['button_text']); ?></button>
                    </div>
                </div>
            </div>
            
            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($product_id); ?>">
            <input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>">
            <input type="hidden" name="variation_id" class="variation_id" value="0">
        </form>
        <?php else: ?>
            <div class="quantity-controls">
                <div class="quantity">
                    <button type="button" class="minus">-</button>
                    <input type="number" class="qty" name="quantity" value="1" min="1" step="1">
                    <button type="button" class="plus">+</button>
                </div>
                <button type="button" class="add-to-cart-button <?php echo esc_attr($atts['button_class']); ?>" data-product-id="<?php echo esc_attr($product_id); ?>"><?php echo esc_html($atts['button_text']); ?></button>
            </div>
        <?php endif; ?>
        
        <div class="volume-discount-info"></div>
        
        <?php if ($atts['show_discounts'] === 'yes' && !empty($discount_settings)): ?>
        <div class="volume-discount-table">
            <p>Скидки по количеству:</p>
            <ul>
                <?php foreach ($discount_settings as $setting): ?>
                    <li>
                        <?php if ($setting['max'] < 999999): ?>
                            От <?php echo esc_html($setting['min']); ?> до <?php echo esc_html($setting['max']); ?> шт: <?php echo esc_html($setting['percentage']); ?>%
                        <?php else: ?>
                            От <?php echo esc_html($setting['min']); ?> шт: <?php echo esc_html($setting['percentage']); ?>%
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="cart-notification"></div>
    </div>
    
    <!-- CSS стили -->
    <style>
    #<?php echo esc_attr($unique_id); ?> {
        margin-bottom: 20px;
    }
    #<?php echo esc_attr($unique_id); ?> .quantity-controls {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        flex-wrap: wrap;
        gap: 15px;
    }
    #<?php echo esc_attr($unique_id); ?> .quantity {
        display: flex;
        align-items: center;
    }
    #<?php echo esc_attr($unique_id); ?> .quantity button {
        width: 30px;
        height: 30px;
        background: #f0f0f0;
        border: 1px solid #ddd;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }
    #<?php echo esc_attr($unique_id); ?> .quantity button:hover {
        background: #e0e0e0;
    }
    #<?php echo esc_attr($unique_id); ?> .quantity input {
        width: 60px;
        height: 30px;
        text-align: center;
        border: 1px solid #ddd;
        margin: 0 5px;
    }
    #<?php echo esc_attr($unique_id); ?> .add-to-cart-button,
    #<?php echo esc_attr($unique_id); ?> .single_add_to_cart_button {
        padding: 8px 15px;
        min-height: 30px;
    }
    #<?php echo esc_attr($unique_id); ?> .volume-discount-info {
        margin-top: 15px;
    }
    #<?php echo esc_attr($unique_id); ?> .discount-info {
        background: #f8f8f8;
        padding: 10px;
        border: 1px solid #eee;
        border-radius: 4px;
    }
    #<?php echo esc_attr($unique_id); ?> .discount-info p {
        margin: 5px 0;
    }
    #<?php echo esc_attr($unique_id); ?> .volume-discount-table {
        margin-top: 20px;
    }
    #<?php echo esc_attr($unique_id); ?> .volume-discount-table ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    #<?php echo esc_attr($unique_id); ?> .volume-discount-table li {
        padding: 5px 0;
        border-bottom: 1px solid #eee;
    }
    #<?php echo esc_attr($unique_id); ?> .cart-notification {
        margin-top: 15px;
    }
    #<?php echo esc_attr($unique_id); ?> .success-message {
        color: green;
        background: #f0fff0;
        padding: 10px;
        border: 1px solid #d0e9c6;
        border-radius: 4px;
    }
    #<?php echo esc_attr($unique_id); ?> .error-message {
        color: red;
        background: #fff0f0;
        padding: 10px;
        border: 1px solid #ebccd1;
        border-radius: 4px;
    }
    #<?php echo esc_attr($unique_id); ?> .variations {
        margin-bottom: 15px;
    }
    #<?php echo esc_attr($unique_id); ?> .variation-row {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
    }
    #<?php echo esc_attr($unique_id); ?> .variation-row .label {
        width: 100px;
        min-width: 100px;
    }
    #<?php echo esc_attr($unique_id); ?> .variation-row .value {
        flex: 1;
    }
    #<?php echo esc_attr($unique_id); ?> .reset-variations-wrapper {
        text-align: right;
        margin-bottom: 10px;
    }
    #<?php echo esc_attr($unique_id); ?> .woocommerce-variation-price {
        margin-bottom: 10px;
        font-weight: bold;
    }
    #<?php echo esc_attr($unique_id); ?> .product-price {
        margin-bottom: 15px;
        font-size: 18px;
        font-weight: bold;
    }
    </style>
    
    <!-- JavaScript функционал -->
    <script>
    jQuery(document).ready(function($) {
        var containerId = '#<?php echo esc_js($unique_id); ?>';
        var $container = $(containerId);
        var productId = <?php echo esc_js($product_id); ?>;
        var isVariable = <?php echo esc_js($is_variable ? 'true' : 'false'); ?>;
        var variationsData = <?php echo wp_json_encode($available_variations); ?>;
        
        // Функция для форматирования цены
        function formatPrice(price) {
            return Math.round(price).toString().replace(/(\d)(?=(\d{3})+(?!\d))/g, "$1 ") + " руб.";
        }
        
        // Функция для обновления информации о скидке
        function updateDiscountInfo() {
            var $quantityInput = $container.find(".qty");
            var quantity = parseInt($quantityInput.val()) || 1;
            var currentProductId = productId;
            var $discountInfo = $container.find(".volume-discount-info");
            
            // Для вариативных товаров
            if (isVariable) {
                var variationId = $container.find("input.variation_id").val();
                if (!variationId || variationId == 0) {
                    $discountInfo.html("");
                    return;
                }
                currentProductId = variationId;
            }
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: "POST",
                data: {
                    action: "get_volume_discount_info",
                    product_id: currentProductId,
                    quantity: quantity
                },
                success: function(response) {
                    if (response.success) {
                        $discountInfo.html(response.data.html);
                    } else {
                        $discountInfo.html("");
                    }
                }
            });
        }
        
        // Обработка изменения количества
        $container.on("change", ".qty", function() {
            updateDiscountInfo();
        });
        
        // Обработка кнопок плюс/минус
        $container.on("click", ".plus", function() {
            var $quantityInput = $container.find(".qty");
            var val = parseInt($quantityInput.val()) + 1;
            $quantityInput.val(val).trigger("change");
        });
        
        $container.on("click", ".minus", function() {
            var $quantityInput = $container.find(".qty");
            var val = parseInt($quantityInput.val()) - 1;
            if (val < 1) val = 1;
            $quantityInput.val(val).trigger("change");
        });
        
        // Функция поиска подходящей вариации
        function findMatchingVariation(variations, attributes) {
            for (var i = 0; i < variations.length; i++) {
                var variation = variations[i];
                var match = true;
                
                for (var attr_name in attributes) {
                    var val1 = attributes[attr_name];
                    var attr_name_clean = attr_name.replace("attribute_", "");
                    var val2 = variation.attributes[attr_name_clean];
                    
                    if (val1 !== "" && val2 !== "" && val1 !== val2) {
                        match = false;
                        break;
                    }
                }
                
                if (match) {
                    return variation;
                }
            }
            return false;
        }
        
        function hasAnyValue(attributes) {
            for (var attr_name in attributes) {
                if (attributes[attr_name] !== "") {
                    return true;
                }
            }
            return false;
        }
        
        // Инициализация для вариативных товаров
        if (isVariable) {
            var $form = $container.find(".variations_form");
            
            // Обработка изменения атрибутов
            $form.on("change", "select", function() {
                var attributes = {};
                $form.find("select").each(function() {
                    var name = $(this).attr("name");
                    var value = $(this).val() || "";
                    attributes[name] = value;
                });
                
                // Поиск подходящей вариации
                var matchingVariation = findMatchingVariation(variationsData, attributes);
                
                if (matchingVariation) {
                    // Вариация найдена
                    $form.find("input.variation_id").val(matchingVariation.variation_id);
                    
                    // Обновляем цену
                    if (matchingVariation.display_price) {
                        var priceHtml = "<span class=\"price\">" + formatPrice(matchingVariation.display_price) + "</span>";
                        $form.find(".single_variation").html("<div class=\"woocommerce-variation-price\">" + priceHtml + "</div>");
                    }
                    
                    // Обновляем информацию о скидке
                    updateDiscountInfo();
                    
                    // Показываем кнопку добавления в корзину
                    $form.find(".single_add_to_cart_button").prop("disabled", false);
                    
                    // Показываем кнопку сброса
                    $form.find(".reset_variations").css("visibility", "visible");
                } else {
                    // Вариация не найдена
                    $form.find("input.variation_id").val("0");
                    $form.find(".single_variation").html("<div class=\"woocommerce-variation-unavailable\">Выберите опцию</div>");
                    $form.find(".single_add_to_cart_button").prop("disabled", true);
                    $container.find(".volume-discount-info").html("");
                    
                    // Показываем кнопку сброса если есть выбранные атрибуты
                    if (hasAnyValue(attributes)) {
                        $form.find(".reset_variations").css("visibility", "visible");
                    } else {
                        $form.find(".reset_variations").css("visibility", "hidden");
                    }
                }
            });
            
            // Сброс вариаций
            $form.on("click", ".reset_variations", function(e) {
                e.preventDefault();
                $form.find("select").val("").trigger("change");
                $form.find("input.variation_id").val("0");
                $form.find(".single_variation").html("");
                $form.find(".single_add_to_cart_button").prop("disabled", true);
                $container.find(".volume-discount-info").html("");
                $(this).css("visibility", "hidden");
            });
            
            // Обработка отправки формы
            $form.on("submit", function(e) {
                e.preventDefault();
                
                var variationId = $form.find("input.variation_id").val();
                if (!variationId || variationId == "0") {
                    return false;
                }
                
                var quantity = parseInt($container.find(".qty").val());
                var data = {};
                
                // Собираем данные формы
                $form.find("select").each(function() {
                    var name = $(this).attr("name");
                    var value = $(this).val();
                    data[name] = value;
                });
                
                data["action"] = "add_to_cart_with_volume_discount";
                data["product_id"] = productId;
                data["variation_id"] = variationId;
                data["quantity"] = quantity;
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: "POST",
                    data: data,
                    beforeSend: function() {
                        $form.find(".single_add_to_cart_button").addClass("loading").prop("disabled", true);
                    },
                    success: function(response) {
                        $form.find(".single_add_to_cart_button").removeClass("loading").prop("disabled", false);
                        
                        if (response.success) {
                            $container.find(".cart-notification").html("<div class=\"success-message\">" + response.data.message + " <a href=\"<?php echo wc_get_cart_url(); ?>\">Перейти в корзину</a></div>");
                            
                            if (response.data.fragments) {
                                $.each(response.data.fragments, function(key, value) {
                                    $(key).replaceWith(value);
                                });
                            }
                            
                            $(document.body).trigger("wc_fragments_refreshed");
                        } else {
                            $container.find(".cart-notification").html("<div class=\"error-message\">" + response.data.message + "</div>");
                        }
                        
                        setTimeout(function() {
                            $container.find(".cart-notification").html("");
                        }, 5000);
                    }
                });
            });
        }
        
        // Обработка кнопки добавления в корзину для простых товаров
        $container.on("click", ".add-to-cart-button", function() {
            var quantity = parseInt($container.find(".qty").val());
            var $button = $(this);
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: "POST",
                data: {
                    action: "add_to_cart_with_volume_discount",
                    product_id: productId,
                    quantity: quantity
                },
                beforeSend: function() {
                    $button.addClass("loading").prop("disabled", true);
                },
                success: function(response) {
                    $button.removeClass("loading").prop("disabled", false);
                    
                    if (response.success) {
                        $container.find(".cart-notification").html("<div class=\"success-message\">" + response.data.message + " <a href=\"<?php echo wc_get_cart_url(); ?>\">Перейти в корзину</a></div>");
                        
                        if (response.data.fragments) {
                            $.each(response.data.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                        }
                        
                        $(document.body).trigger("wc_fragments_refreshed");
                    } else {
                        $container.find(".cart-notification").html("<div class=\"error-message\">" + response.data.message + "</div>");
                    }
                    
                    setTimeout(function() {
                        $container.find(".cart-notification").html("");
                    }, 5000);
                }
            });
        });
        
        // Инициализация
        if (!isVariable) {
            updateDiscountInfo();
        }
    });
    </script>
    <?php
    
    return ob_get_clean();
}
add_shortcode('volume_discount_controls', 'volume_discount_controls_shortcode');

// Добавляем поля для настройки скидок в админке товара
function add_volume_discount_fields() {
    global $post;
    
    if (!isset($post->ID) || get_post_type($post->ID) !== 'product') return;
    
    echo '<div class="options_group">';
    echo '<h3 style="padding-left:12px;">Настройки скидок по объему</h3>';
    echo '<p style="padding-left:12px;">Укажите диапазоны количества товаров и соответствующие проценты скидок (до 7 правил)</p>';
    
    for ($i = 1; $i <= 7; $i++) {
        echo '<div class="volume-discount-field-group" style="padding-left:12px;">';
        
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
        } else {
            echo '<div class="form-field" style="width:80px;"></div>';
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
add_action('woocommerce_product_options_pricing', 'add_volume_discount_fields');

// Сохраняем настройки скидок при сохранении товара
function save_volume_discount_fields($post_id) {
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
add_action('woocommerce_process_product_meta', 'save_volume_discount_fields');

// Добавляем поля для настройки скидок в админке вариаций товара
function add_variation_volume_discount_fields($loop, $variation_data, $variation) {
    echo '<div class="options_group">';
    echo '<h4>Настройки скидок по объему для вариации</h4>';
    
    for ($i = 1; $i <= 7; $i++) {
        echo '<div class="volume-discount-field-group">';
        
        woocommerce_wp_text_input(array(
            'id' => '_volume_discount_min_' . $i . '[' . $loop . ']',
            'label' => 'От (шт)',
            'type' => 'number',
            'custom_attributes' => array('step' => '1', 'min' => '1'),
            'wrapper_class' => 'form-field',
            'value' => get_post_meta($variation->ID, '_volume_discount_min_' . $i, true)
        ));
        
        if ($i < 7) {
            woocommerce_wp_text_input(array(
                'id' => '_volume_discount_max_' . $i . '[' . $loop . ']',
                'label' => 'До (шт)',
                'type' => 'number',
                'custom_attributes' => array('step' => '1', 'min' => '1'),
                'wrapper_class' => 'form-field',
                'value' => get_post_meta($variation->ID, '_volume_discount_max_' . $i, true)
            ));
        } else {
            echo '<div class="form-field" style="width:80px;"></div>';
        }
        
        woocommerce_wp_text_input(array(
            'id' => '_volume_discount_percentage_' . $i . '[' . $loop . ']',
            'label' => 'Скидка (%)',
            'type' => 'text',
            'wrapper_class' => 'form-field',
            'value' => get_post_meta($variation->ID, '_volume_discount_percentage_' . $i, true)
        ));
        
        echo '</div>';
    }
    
    echo '</div>';
}
add_action('woocommerce_product_after_variable_attributes', 'add_variation_volume_discount_fields', 10, 3);

// Сохраняем настройки скидок при сохранении вариации товара
function save_variation_volume_discount_fields($variation_id, $loop) {
    for ($i = 1; $i <= 7; $i++) {
        $min_field = '_volume_discount_min_' . $i;
        
        if (isset($_POST[$min_field][$loop])) {
            update_post_meta($variation_id, $min_field, wc_clean($_POST[$min_field][$loop]));
        }
        
        if ($i < 7) {
            $max_field = '_volume_discount_max_' . $i;
            if (isset($_POST[$max_field][$loop])) {
                update_post_meta($variation_id, $max_field, wc_clean($_POST[$max_field][$loop]));
            }
        }
        
        $percentage_field = '_volume_discount_percentage_' . $i;
        if (isset($_POST[$percentage_field][$loop])) {
            $percentage_value = str_replace(',', '.', wc_clean($_POST[$percentage_field][$loop]));
            update_post_meta($variation_id, $percentage_field, $percentage_value);
        }
    }
}
add_action('woocommerce_save_product_variation', 'save_variation_volume_discount_fields', 10, 2);

// Добавляем стили для админки
function volume_discount_admin_styles() {
    ?>
    <style>
    .volume-discount-field-group {
        display: flex;
        align-items: center;
        margin-bottom: 5px;
    }
    .volume-discount-field-group .form-field {
        margin: 0 10px 0 0 !important;
        width: 80px;
    }
    </style>
    <?php
}
add_action('admin_head', 'volume_discount_admin_styles');

// Добавляем глобальные CSS стили для мини-корзины
function volume_discount_global_styles() {
    ?>
    <style>
    .mini-cart-discount {
        font-size: 12px;
        color: green;
        margin-top: 5px;
    }
    </style>
    <?php
}
add_action('wp_head', 'volume_discount_global_styles');

// Функция для работы с ценами вариаций
function get_variation_price_with_discount($price, $variation, $parent_product = null) {
    if (is_cart()) return $price; // В корзине скидки уже применены
    
    // Получаем количество из запроса (если есть)
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    
    // Получаем процент скидки
    $discount_percentage = get_volume_discount_percentage($quantity, $variation->get_id());
    
    if ($discount_percentage > 0) {
        $price = $price * (1 - ($discount_percentage / 100));
    }
    
    return $price;
}
add_filter('woocommerce_product_variation_get_price', 'get_variation_price_with_discount', 10, 3);

// Исправляем проблему с вариативными товарами
function fix_variation_price_display($variation_data, $product, $variation) {
    if (isset($variation_data['price_html']) && $variation_data['price_html'] === '') {
        $variation_data['price_html'] = '<span class="price">' . format_price_without_zeros(wc_price($variation->get_price())) . '</span>';
    }
    return $variation_data;
}
add_filter('woocommerce_available_variation', 'fix_variation_price_display', 10, 3);