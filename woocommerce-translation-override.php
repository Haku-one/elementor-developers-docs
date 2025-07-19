<?php
/**
 * Переопределение переводов WooCommerce
 * Замена "Сэкономьте" на "Скидка"
 */

// Запретить прямой доступ
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Переопределение переводов WooCommerce
 */
function override_woocommerce_translations($translation, $text, $domain) {
    // Если это WooCommerce или WooCommerce блоки
    if (in_array($domain, ['woocommerce', 'woo-gutenberg-products-block', 'woocommerce-blocks'])) {
        // Переводы для замены
        $translations = array(
            'Save' => 'Скидка',
            'Сэкономьте' => 'Скидка',
            'You save' => 'Скидка',
            'Savings' => 'Скидка',
        );
        
        // Проверяем есть ли замена для данного текста
        if (isset($translations[$text])) {
            return $translations[$text];
        }
    }
    
    return $translation;
}

// Добавляем фильтр для переопределения переводов
add_filter('gettext', 'override_woocommerce_translations', 20, 3);
add_filter('gettext_with_context', 'override_woocommerce_translations', 20, 3);

/**
 * Альтернативный способ через JavaScript для блоков
 */
function custom_woocommerce_blocks_script() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Заменяем текст "Сэкономьте" на "Скидка" в блоках WooCommerce
        function replaceText() {
            const elements = document.querySelectorAll('.wc-block-components-sale-badge, .wc-block-components-product-badge');
            elements.forEach(function(element) {
                if (element.textContent.includes('Сэкономьте')) {
                    element.innerHTML = element.innerHTML.replace(/Сэкономьте/g, 'Скидка');
                }
            });
        }
        
        // Выполняем сразу
        replaceText();
        
        // Наблюдаем за изменениями DOM для динамически загружаемых блоков
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length > 0) {
                    replaceText();
                }
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    });
    </script>
    <?php
}

// Добавляем скрипт в футер
add_action('wp_footer', 'custom_woocommerce_blocks_script');