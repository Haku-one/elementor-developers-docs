<?php
/**
 * Test file for volume discounts
 * Включите этот файл в WordPress для тестирования
 */

// Защита от прямого доступа
if (!defined('ABSPATH')) {
    exit;
}

// Подключаем основной файл
require_once 'woocommerce-volume-discounts-final.php';

// Инициализируем плагин
new WC_Volume_Discounts_Final();

// Добавляем шорткод на страницу для тестирования
add_action('wp_head', function() {
    ?>
    <style>
    .volume-discount-test {
        max-width: 600px;
        margin: 20px auto;
        padding: 20px;
        border: 1px solid #ddd;
        background: #f9f9f9;
    }
    </style>
    <?php
});

// Функция для отображения тестовой страницы
function display_volume_discount_test() {
    if (!is_page() || !current_user_can('administrator')) {
        return;
    }
    
    global $post;
    if (!$post || $post->post_name !== 'test-volume-discount') {
        return;
    }
    
    echo '<div class="volume-discount-test">';
    echo '<h2>Тест системы скидок по объему</h2>';
    
    // Тестируем с конкретным product_id = 81 (из вашего примера)
    echo '<h3>Тест с товаром ID: 81</h3>';
    echo do_shortcode('[volume_discount_controls product_id="81" show_price="yes" show_discounts="yes"]');
    
    echo '</div>';
}

add_action('the_content', 'display_volume_discount_test');
?>