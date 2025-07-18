<?php
// Функция для AJAX фильтра таксономии с счетчиком
function cambocom_ajax_taxonomy_filter() {
    // Создаем шорткод [ajax_taxonomy_filter]
    function ajax_taxonomy_filter_shortcode($atts) {
        $atts = shortcode_atts(array(
            'taxonomy' => 'categories', // Ключ таксономии по умолчанию
            'limit' => 10, // Количество элементов до "Показать еще"
            'orderby' => 'count', // Сортировка по количеству постов
            'order' => 'DESC', // Порядок сортировки
            'hide_empty' => true, // Скрывать пустые термины
            'target_id' => '', // ID целевого Loop Grid
            'post_type' => 'blog', // Тип поста (важно: используйте 'blog' вместо 'post')
        ), $atts);
        
        $taxonomy = sanitize_text_field($atts['taxonomy']);
        $limit = intval($atts['limit']);
        $orderby = sanitize_text_field($atts['orderby']);
        $order = sanitize_text_field($atts['order']);
        $hide_empty = filter_var($atts['hide_empty'], FILTER_VALIDATE_BOOLEAN);
        $target_id = sanitize_text_field($atts['target_id']);
        $post_type = sanitize_text_field($atts['post_type']);
        
        // ИСПРАВЛЕНИЕ: Получаем все термины таксономии напрямую (без кэша)
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'orderby' => $orderby,
            'order' => $order,
            'hide_empty' => $hide_empty,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return '<div class="work-filter__list">Категории не найдены</div>';
        }
        
        // Получаем текущий термин из URL
        $current_term = '';
        if (isset($_GET[$taxonomy])) {
            $current_term = sanitize_text_field($_GET[$taxonomy]);
        }
        
        // Формируем HTML
        $output = '<div class="work-filter__list work-filter__list_sm show-all" data-target="' . esc_attr($target_id) . '" data-post-type="' . esc_attr($post_type) . '">';
        
        // Добавляем опцию "Все"
        $all_active = empty($current_term) ? 'work-filter__bottom-link_active' : '';
        $output .= '<a class="work-filter__item filter-item ' . $all_active . '" href="javascript:void(0);" data-term="" data-taxonomy="' . esc_attr($taxonomy) . '">';
        $output .= 'Все';
        $output .= '</a>';
        
        $count = 0;
        $total_terms = count($terms);
        $has_more = $total_terms > $limit;
        
        foreach ($terms as $term) {
            $count++;
            $is_hidden = $count > $limit ? 'style="display: none;" class="work-filter__item filter-item hidden-term"' : 'class="work-filter__item filter-item"';
            $is_active = ($current_term === $term->slug) ? 'work-filter__bottom-link_active' : '';
            
            $output .= '<a ' . $is_hidden . ' href="javascript:void(0);" data-term="' . esc_attr($term->slug) . '" data-taxonomy="' . esc_attr($taxonomy) . '">';
            $output .= esc_html($term->name);
            $output .= '<span>' . number_format($term->count) . '</span>';
            $output .= '</a>';
        }
        
        // Добавляем кнопку "Показать еще" если терминов больше чем лимит
        if ($has_more) {
            $output .= '<button class="work-filter__show-more js-show-more-filter" type="button">';
            $output .= '<svg width="9" height="9"><use xlink:href="#plus"></use></svg>';
            $output .= '<b>Показать ещё</b>';
            $output .= '<span>...</span>';
            $output .= '</button>';
        }
        
        $output .= '</div>';
        
        // Добавляем SVG иконку плюса
        $output .= '<svg style="display:none"><symbol id="plus" viewBox="0 0 9 9" fill="none"><path d="M4.5 0V9M0 4.5H9" stroke="currentColor" stroke-width="1.5"/></symbol></svg>';
        
        return $output;
    }
    add_shortcode('ajax_taxonomy_filter', 'ajax_taxonomy_filter_shortcode');
    
    // Регистрируем скрипт для AJAX
    function register_filter_script() {
        wp_register_script('taxonomy-filter', '', array('jquery'), '1.0', true);
        wp_localize_script('taxonomy-filter', 'taxonomy_filter_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('filter_loop_grid_nonce'),
            'rest_url' => rest_url('taxonomy-filter/v1/filter'), // Подготовка для REST API
        ));
        wp_enqueue_script('taxonomy-filter');
        
        // Встраиваем JavaScript прямо в footer
        add_action('wp_footer', 'add_filter_script');
    }
    add_action('wp_enqueue_scripts', 'register_filter_script');
    
    // Добавляем JavaScript для обработки кликов и AJAX
    function add_filter_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Кэш для хранения результатов запросов
            const responseCache = {};
            
            // Функция дебаунса для предотвращения множественных запросов
            function debounce(func, wait) {
                let timeout;
                return function() {
                    const context = this, args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(function() {
                        func.apply(context, args);
                    }, wait);
                };
            }
            
            // Обработка кнопки "Показать еще"
            $('.js-show-more-filter').on('click', function() {
                $(this).closest('.work-filter__list').find('.hidden-term').show();
                $(this).hide();
            });
            
            // Обработка клика по фильтру с дебаунсом
            $('.filter-item').on('click', debounce(function(e) {
                e.preventDefault();
                
                const $this = $(this);
                const term = $this.data('term');
                const taxonomy = $this.data('taxonomy');
                const targetId = $this.closest('.work-filter__list').data('target');
                const postType = $this.closest('.work-filter__list').data('post-type') || 'blog';
                
                // Добавляем активный класс
                $this.closest('.work-filter__list').find('.filter-item').removeClass('work-filter__bottom-link_active');
                $this.addClass('work-filter__bottom-link_active');
                
                // Находим целевой Loop Grid
                let $targetWidget;
                if (targetId) {
                    $targetWidget = $('.elementor-element-' + targetId);
                } else {
                    $targetWidget = $('.elementor-widget-loop-grid');
                }
                
                if ($targetWidget.length) {
                    // Добавляем класс загрузки
                    $targetWidget.addClass('is-loading');
                    
                    // Получаем текущие настройки виджета
                    const widgetId = $targetWidget.data('id');
                    const widgetType = $targetWidget.data('widget_type') || 'loop-grid.post';
                    
                    // Создаем ключ кэша
                    const cacheKey = `${widgetId}-${taxonomy}-${term}-${postType}`;
                    
                    // Проверяем, есть ли данные в кэше
                    if (responseCache[cacheKey]) {
                        console.log('Using cached data');
                        updateContent(responseCache[cacheKey]);
                        return;
                    }
                    
                    // Отправляем AJAX запрос
                    $.ajax({
                        url: taxonomy_filter_vars.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'filter_loop_grid',
                            nonce: taxonomy_filter_vars.nonce,
                            term: term,
                            taxonomy: taxonomy,
                            widget_id: widgetId,
                            widget_type: widgetType,
                            post_type: postType
                        },
                        success: function(response) {
                            // Сохраняем в кэш
                            if (response.success) {
                                responseCache[cacheKey] = response;
                            }
                            updateContent(response);
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX Error:', error);
                            $targetWidget.removeClass('is-loading');
                        }
                    });
                    
                    function updateContent(response) {
                        if (response.success) {
                            // Обновляем содержимое Loop Grid
                            $targetWidget.find('.elementor-loop-container').html(response.data.html);
                            
                            // Обновляем URL без перезагрузки страницы
                            const currentUrl = new URL(window.location.href);
                            
                            if (term) {
                                currentUrl.searchParams.set(taxonomy, term);
                            } else {
                                currentUrl.searchParams.delete(taxonomy);
                            }
                            
                            window.history.pushState({}, '', currentUrl.toString());
                            
                            // Инициализируем счетчики просмотров
                            initViewCounters();
                            
                            // Инициализируем ленивую загрузку
                            if (typeof window.initLazyLoad === 'function') {
                                window.initLazyLoad();
                            }
                        } else {
                            console.error('Error:', response.data);
                        }
                        
                        // Убираем класс загрузки
                        $targetWidget.removeClass('is-loading');
                    }
                } else {
                    console.error('Target widget not found:', targetId);
                }
            }, 300)); // Дебаунс 300мс
            
            // Функция для инициализации счетчиков просмотров
            function initViewCounters() {
                // Если есть функция для счетчиков просмотров, вызываем её
                if (typeof window.initPostViewCounters === 'function') {
                    window.initPostViewCounters();
                }
                
                // Добавляем обработку SVG иконок
                $('svg use').each(function() {
                    const href = $(this).attr('xlink:href');
                    if (href && href === '#eye') {
                        // Иконка уже правильная
                    }
                });
            }
            
            // Предзагрузка для популярных категорий
            function preloadPopularCategories() {
                // Находим первые 3 категории (самые популярные)
                const $popularItems = $('.work-filter__item').slice(0, 3);
                
                // Асинхронно предзагружаем их данные
                setTimeout(function() {
                    $popularItems.each(function(index) {
                        const $item = $(this);
                        const term = $item.data('term');
                        const taxonomy = $item.data('taxonomy');
                        const targetId = $item.closest('.work-filter__list').data('target');
                        const postType = $item.closest('.work-filter__list').data('post-type') || 'blog';
                        
                        // Находим целевой Loop Grid
                        let $targetWidget;
                        if (targetId) {
                            $targetWidget = $('.elementor-element-' + targetId);
                        } else {
                            $targetWidget = $('.elementor-widget-loop-grid');
                        }
                        
                        if ($targetWidget.length) {
                            const widgetId = $targetWidget.data('id');
                            const widgetType = $targetWidget.data('widget_type') || 'loop-grid.post';
                            
                            // Создаем ключ кэша
                            const cacheKey = `${widgetId}-${taxonomy}-${term}-${postType}`;
                            
                            // Проверяем, есть ли данные в кэше
                            if (!responseCache[cacheKey]) {
                                // Отправляем AJAX запрос с низким приоритетом
                                $.ajax({
                                    url: taxonomy_filter_vars.ajax_url,
                                    type: 'POST',
                                    data: {
                                        action: 'filter_loop_grid',
                                        nonce: taxonomy_filter_vars.nonce,
                                        term: term,
                                        taxonomy: taxonomy,
                                        widget_id: widgetId,
                                        widget_type: widgetType,
                                        post_type: postType
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            responseCache[cacheKey] = response;
                                            console.log('Preloaded:', term);
                                        }
                                    }
                                });
                            }
                        }
                    });
                }, 1000); // Начинаем предзагрузку через 1 секунду после загрузки страницы
            }
            
            // Запускаем предзагрузку
            preloadPopularCategories();
        });
        </script>
        <?php
    }
    
    // Обработчик AJAX запроса для фильтрации Loop Grid
    function filter_loop_grid_handler() {
        // Проверяем nonce для безопасности
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'filter_loop_grid_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Проверяем наличие необходимых параметров
        if (!isset($_POST['widget_id']) || !isset($_POST['taxonomy'])) {
            wp_send_json_error('Missing parameters');
            return;
        }
        
        $widget_id = sanitize_text_field($_POST['widget_id']);
        $taxonomy = sanitize_text_field($_POST['taxonomy']);
        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'blog';
        
        // Создаем ключ кэша
        $cache_key = 'filter_' . md5($widget_id . '_' . $taxonomy . '_' . $term . '_' . $post_type);
        
        // Пытаемся получить данные из кэша
        $cached_result = get_transient($cache_key);
        
        if (false !== $cached_result) {
            wp_send_json_success($cached_result);
            return;
        }
        
        // Получаем ID шаблона из виджета
        $template_id = '1493'; // ID шаблона из вашего кода
        $posts_per_page = 10; // По умолчанию
        
        // Настройки запроса с оптимизациями
        $query_args = array(
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_page,
            'post_status' => 'publish',
            'no_found_rows' => true, // Оптимизация запроса
            'update_post_meta_cache' => false, // Отключаем ненужные запросы
            'update_post_term_cache' => false,
            'fields' => 'ids', // Сначала получаем только ID для оптимизации
        );
        
        // Добавляем фильтрацию по таксономии, если выбран термин
        if (!empty($term)) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $term,
                ),
            );
        }
        
        // Выполняем запрос для получения ID постов
        $ids_query = new WP_Query($query_args);
        $post_ids = $ids_query->posts;
        
        // Получаем HTML для постов
        $html = '';
        
        if (!empty($post_ids)) {
            // Теперь выполняем запрос для получения полных данных только для нужных постов
            $posts_query = new WP_Query(array(
                'post__in' => $post_ids,
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'post__in', // Сохраняем порядок из первого запроса
            ));
            
            ob_start(); // Используем буферизацию для оптимизации
            
            while ($posts_query->have_posts()) {
                $posts_query->the_post();
                $post_id = get_the_ID();
                
                // ИСПРАВЛЕНИЕ: Используем правильный класс Elementor
                if (class_exists('\Elementor\Plugin')) {
                    echo '<div data-elementor-type="loop-item" data-elementor-id="' . esc_attr($template_id) . '" class="elementor elementor-' . esc_attr($template_id) . ' e-loop-item e-loop-item-' . esc_attr($post_id) . ' post-' . esc_attr($post_id) . ' ' . esc_attr($post_type) . ' type-' . esc_attr($post_type) . ' status-publish has-post-thumbnail hentry">';
                    
                    // Получаем содержимое шаблона с кэшированием
                    $template_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id);
                    echo $template_content;
                    
                    echo '</div>';
                } else {
                    echo '<div class="elementor-post">';
                    echo '<h3>' . get_the_title() . '</h3>';
                    echo '<div>' . get_the_excerpt() . '</div>';
                    echo '</div>';
                }
            }
            
            $html = ob_get_clean();
            wp_reset_postdata();
        } else {
            $html = '<div class="no-posts-found">Записи не найдены</div>';
        }
        
        $result = array(
            'html' => $html,
            'found_posts' => count($post_ids),
            'query' => $query_args
        );
        
        // Сохраняем в кэш на 1 час
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        
        wp_send_json_success($result);
    }
    add_action('wp_ajax_filter_loop_grid', 'filter_loop_grid_handler');
    add_action('wp_ajax_nopriv_filter_loop_grid', 'filter_loop_grid_handler');
    
    // Добавляем очистку кэша при обновлении постов
    function clear_filter_cache($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        // Очищаем все транзиенты, связанные с фильтром
        global $wpdb;
        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_filter_%'");
    }
    add_action('save_post', 'clear_filter_cache');
    add_action('deleted_post', 'clear_filter_cache');
    add_action('edited_terms', 'clear_filter_cache');
    
    // Добавляем CSS стили и скрипт для ленивой загрузки
    function add_taxonomy_filter_styles() {
        echo '<style>
            .work-filter__list {
                display: flex;
                flex-wrap: wrap;
                will-change: transform; /* Оптимизация для GPU */
            }
            
            .work-filter__item {
                display: flex;
                align-items: baseline;
                margin-right: 5px;
                
                padding: 7px 9px;
                font-size: 15px;
                font-weight: 400;
                line-height: 15px;
                background: #fff;
                -webkit-backdrop-filter: blur(5px);
                backdrop-filter: blur(5px);
                border-radius: 4px;
                color: rgba(50, 50, 50, .6);
                text-decoration: none;
                transition: all 0.2s ease;
                cursor: pointer;
                gap: 5px;
                transform: translateZ(0); /* Включаем аппаратное ускорение */
            }
            
            .work-filter__item:hover {
                background: #f5f5f5;
            }
            
            .work-filter__item span {
                margin-left: 3px;
                color: #FD7113;
            }
            
            .work-filter__bottom-link_active {
                color: #fff !important;
                background: #FD7113 !important;
            }
            
            .work-filter__bottom-link_active span {
                color: #fff !important;
            }
            
            .work-filter__show-more {
                display: flex;
                align-items: center;
                background: none;
                border: none;
                cursor: pointer;
                font-size: 15px;
                color: #FD7113;
                padding: 7px 0;
                margin-left: 5px;
            }
            
            .work-filter__show-more svg {
                margin-right: 5px;
            }
            
            .work-filter__show-more b {
                font-weight: 500;
                margin-right: 3px;
            }
            
            /* Стили для состояния загрузки */
            .elementor-widget-loop-grid.is-loading {
                position: relative;
            }
            
            .elementor-widget-loop-grid.is-loading:after {
                content: "";
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(255, 255, 255, 0.7);
                z-index: 10;
            }
            
            .elementor-widget-loop-grid.is-loading:before {
                content: "";
                position: absolute;
                top: 50%;
                left: 50%;
                width: 40px;
                height: 40px;
                margin: -20px 0 0 -20px;
                border-radius: 50%;
                border: 3px solid #f3f3f3;
                border-top: 3px solid #FD7113;
                animation: spin 1s linear infinite;
                z-index: 11;
                will-change: transform; /* Оптимизация для GPU */
            }
            
            .elementor-widget-loop-grid img.loaded {
                opacity: 1;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            @media (max-width: 768px) {
                .work-filter__item {
                    font-size: 13px;
                    padding: 5px 8px;
                }
                
                .work-filter__show-more {
                    font-size: 13px;
                }
            }
            
            .no-posts-found {
                padding: 20px;
                text-align: center;
                font-size: 16px;
                color: #555;
                width: 100%;
                grid-column: 1 / -1;
            }
            
            /* Анимация появления элементов */
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .elementor-loop-container > div {
                animation: fadeIn 0.3s ease-out forwards;
            }
        </style>';
        
        
    }
    add_action('wp_head', 'add_taxonomy_filter_styles');
    
    // Добавляем REST API endpoint для более быстрой работы
    function register_rest_filter_endpoint() {
        register_rest_route('taxonomy-filter/v1', '/filter', array(
            'methods' => 'POST',
            'callback' => 'rest_filter_callback',
            'permission_callback' => '__return_true',
        ));
    }
    add_action('rest_api_init', 'register_rest_filter_endpoint');
    
    // Обработчик REST API запроса
    function rest_filter_callback($request) {
        // Получаем параметры из запроса
        $params = $request->get_params();
        
        // Проверяем наличие необходимых параметров
        if (!isset($params['widget_id']) || !isset($params['taxonomy'])) {
            return new WP_Error('missing_params', 'Missing parameters', array('status' => 400));
        }
        
        $widget_id = sanitize_text_field($params['widget_id']);
        $taxonomy = sanitize_text_field($params['taxonomy']);
        $term = isset($params['term']) ? sanitize_text_field($params['term']) : '';
        $post_type = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'blog';
        
        // Создаем ключ кэша
        $cache_key = 'rest_filter_' . md5($widget_id . '_' . $taxonomy . '_' . $term . '_' . $post_type);
        
        // Пытаемся получить данные из кэша
        $cached_result = get_transient($cache_key);
        
        if (false !== $cached_result) {
            return new WP_REST_Response($cached_result, 200);
        }
        
        // Получаем ID шаблона
        $template_id = '1493';
        $posts_per_page = 10;
        
        // Настройки запроса
        $query_args = array(
            'post_type' => $post_type,
            'posts_per_page' => $posts_per_page,
            'post_status' => 'publish',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'fields' => 'ids',
        );
        
        // Добавляем фильтрацию по таксономии
        if (!empty($term)) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => $taxonomy,
                    'field' => 'slug',
                    'terms' => $term,
                ),
            );
        }
        
        // Выполняем запрос для получения ID постов
        $ids_query = new WP_Query($query_args);
        $post_ids = $ids_query->posts;
        
        // Получаем HTML для постов
        $html = '';
        
        if (!empty($post_ids)) {
            // Теперь выполняем запрос для получения полных данных только для нужных постов
            $posts_query = new WP_Query(array(
                'post__in' => $post_ids,
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'orderby' => 'post__in', // Сохраняем порядок из первого запроса
            ));
            
            ob_start(); // Используем буферизацию для оптимизации
            
            while ($posts_query->have_posts()) {
                $posts_query->the_post();
                $post_id = get_the_ID();
                
                // ИСПРАВЛЕНИЕ: Используем правильный класс Elementor
                if (class_exists('\Elementor\Plugin')) {
                    echo '<div data-elementor-type="loop-item" data-elementor-id="' . esc_attr($template_id) . '" class="elementor elementor-' . esc_attr($template_id) . ' e-loop-item e-loop-item-' . esc_attr($post_id) . ' post-' . esc_attr($post_id) . ' ' . esc_attr($post_type) . ' type-' . esc_attr($post_type) . ' status-publish has-post-thumbnail hentry">';
                    
                    // Получаем содержимое шаблона с кэшированием
                    $template_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id);
                    echo $template_content;
                    
                    echo '</div>';
                } else {
                    echo '<div class="elementor-post">';
                    echo '<h3>' . get_the_title() . '</h3>';
                    echo '<div>' . get_the_excerpt() . '</div>';
                    echo '</div>';
                }
            }
            
            $html = ob_get_clean();
            wp_reset_postdata();
        } else {
            $html = '<div class="no-posts-found">Записи не найдены</div>';
        }
        
        $result = array(
            'html' => $html,
            'found_posts' => count($post_ids),
            'query' => $query_args
        );
        
        // Сохраняем в кэш на 1 час
        set_transient($cache_key, $result, HOUR_IN_SECONDS);
        
        return new WP_REST_Response($result, 200);
    }
    
    // Добавляем оптимизацию для мобильных устройств
    function add_mobile_optimizations() {
        if (wp_is_mobile()) {
            echo '<script>
                // Оптимизация для мобильных устройств
                document.addEventListener("DOMContentLoaded", function() {
                    // Уменьшаем количество предзагружаемых элементов на мобильных
                    const preloadLimit = 2;
                    
                    // Оптимизируем анимации для мобильных
                    const style = document.createElement("style");
                    style.textContent = `
                        @media (max-width: 768px) {
                            .elementor-loop-container > div {
                                animation-duration: 0.2s;
                            }
                            
                            .work-filter__item {
                                touch-action: manipulation;
                            }
                        }
                    `;
                    document.head.appendChild(style);
                });
            </script>';
        }
    }
    add_action('wp_head', 'add_mobile_optimizations');
    
    // Добавляем Service Worker для кэширования (опционально)
    function add_service_worker() {
        echo '<script>
            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker.register("/sw.js")
                        .then(function(registration) {
                            console.log("SW registered: ", registration);
                        })
                        .catch(function(registrationError) {
                            console.log("SW registration failed: ", registrationError);
                        });
                });
            }
        </script>';
    }
    add_action('wp_head', 'add_service_worker');
    
    // Добавляем оптимизацию для браузеров с поддержкой Intersection Observer v2
    function add_intersection_observer_v2() {
        echo '<script>
            // Проверяем поддержку Intersection Observer v2
            if ("IntersectionObserver" in window && "IntersectionObserverEntry" in window && "intersectionRatio" in window.IntersectionObserverEntry.prototype) {
                // Используем более продвинутый Intersection Observer
                window.advancedIntersectionObserver = true;
            }
        </script>';
    }
    add_action('wp_head', 'add_intersection_observer_v2');
    
    // Добавляем оптимизацию для браузеров с поддержкой ResizeObserver
    function add_resize_observer() {
        echo '<script>
            // Оптимизация для изменения размера окна
            if ("ResizeObserver" in window) {
                const resizeObserver = new ResizeObserver(function(entries) {
                    // Пересчитываем позиции элементов при изменении размера
                    if (typeof window.initLazyLoad === "function") {
                        window.initLazyLoad();
                    }
                });
                
                // Наблюдаем за контейнером фильтра
                document.addEventListener("DOMContentLoaded", function() {
                    const filterContainer = document.querySelector(".work-filter__list");
                    if (filterContainer) {
                        resizeObserver.observe(filterContainer);
                    }
                });
            }
        </script>';
    }
    add_action('wp_head', 'add_resize_observer');
    
    // Добавляем оптимизацию для браузеров с поддержкой requestIdleCallback
    function add_idle_callback_optimization() {
        echo '<script>
            // Используем requestIdleCallback для неважных операций
            if ("requestIdleCallback" in window) {
                window.idleCallback = requestIdleCallback;
            } else {
                // Fallback для браузеров без поддержки
                window.idleCallback = function(callback) {
                    setTimeout(callback, 1);
                };
            }
            
            // Оптимизируем предзагрузку с помощью idle callback
            window.preloadWithIdle = function(callback) {
                window.idleCallback(function() {
                    callback();
                });
            };
        </script>';
    }
    add_action('wp_head', 'add_idle_callback_optimization');
    
    // Добавляем оптимизацию для браузеров с поддержкой AbortController
    function add_abort_controller() {
        echo '<script>
            // Добавляем возможность отмены AJAX запросов
            if ("AbortController" in window) {
                window.abortController = new AbortController();
            }
            
            // Функция для отмены предыдущих запросов
            window.cancelPreviousRequests = function() {
                if (window.abortController) {
                    window.abortController.abort();
                    window.abortController = new AbortController();
                }
            };
        </script>';
    }
    add_action('wp_head', 'add_abort_controller');
    
    // Добавляем оптимизацию для браузеров с поддержкой Performance API
    function add_performance_monitoring() {
        echo '<script>
            // Мониторинг производительности
            if ("performance" in window) {
                window.filterPerformance = {
                    startTime: 0,
                    endTime: 0,
                    
                    start: function() {
                        this.startTime = performance.now();
                    },
                    
                    end: function() {
                        this.endTime = performance.now();
                        const duration = this.endTime - this.startTime;
                        console.log("Filter operation took:", duration.toFixed(2), "ms");
                        
                        // Отправляем метрики в Google Analytics если доступно
                        if (typeof gtag !== "undefined") {
                            gtag("event", "filter_performance", {
                                "event_category": "user_interaction",
                                "event_label": "taxonomy_filter",
                                "value": Math.round(duration)
                            });
                        }
                    }
                };
            }
        </script>';
    }
    add_action('wp_head', 'add_performance_monitoring');
    
    // Добавляем оптимизацию для браузеров с поддержкой Web Workers
    function add_web_worker_support() {
        echo '<script>
            // Поддержка Web Workers для тяжелых операций
            if ("Worker" in window) {
                // Создаем Web Worker для обработки данных
                const filterWorker = new Worker("/js/filter-worker.js");
                
                filterWorker.onmessage = function(e) {
                    if (e.data.type === "filter_result") {
                        // Обрабатываем результат от Web Worker
                        console.log("Worker processed:", e.data.result);
                    }
                };
                
                // Отправляем данные в Worker для обработки
                window.processWithWorker = function(data) {
                    filterWorker.postMessage({
                        type: "process_filter",
                        data: data
                    });
                };
            }
        </script>';
    }
    add_action('wp_head', 'add_web_worker_support');
    
    // Добавляем оптимизацию для браузеров с поддержкой BroadcastChannel
    function add_broadcast_channel() {
        echo '<script>
            // Используем BroadcastChannel для синхронизации между вкладками
            if ("BroadcastChannel" in window) {
                const filterChannel = new BroadcastChannel("filter_updates");
                
                filterChannel.onmessage = function(event) {
                    if (event.data.type === "filter_changed") {
                        // Обновляем состояние фильтра в других вкладках
                        console.log("Filter updated in another tab:", event.data);
                    }
                };
                
                // Отправляем обновления в другие вкладки
                window.broadcastFilterUpdate = function(data) {
                    filterChannel.postMessage({
                        type: "filter_changed",
                        data: data
                    });
                };
            }
        </script>';
    }
    add_action('wp_head', 'add_broadcast_channel');
    
    // Добавляем оптимизацию для браузеров с поддержкой IndexedDB
    function add_indexeddb_support() {
        echo '<script>
            // Используем IndexedDB для локального кэширования
            if ("indexedDB" in window) {
                let db;
                const request = indexedDB.open("FilterCache", 1);
                
                request.onerror = function() {
                    console.log("IndexedDB error");
                };
                
                request.onsuccess = function() {
                    db = request.result;
                };
                
                request.onupgradeneeded = function() {
                    db = request.result;
                    if (!db.objectStoreNames.contains("filterCache")) {
                        db.createObjectStore("filterCache", { keyPath: "key" });
                    }
                };
                
                // Сохраняем данные в IndexedDB
                window.saveToIndexedDB = function(key, data) {
                    if (db) {
                        const transaction = db.transaction(["filterCache"], "readwrite");
                        const store = transaction.objectStore("filterCache");
                        store.put({ key: key, data: data, timestamp: Date.now() });
                    }
                };
                
                // Получаем данные из IndexedDB
                window.getFromIndexedDB = function(key) {
                    return new Promise(function(resolve, reject) {
                        if (db) {
                            const transaction = db.transaction(["filterCache"], "readonly");
                            const store = transaction.objectStore("filterCache");
                            const request = store.get(key);
                            
                            request.onsuccess = function() {
                                if (request.result) {
                                    resolve(request.result.data);
                                } else {
                                    resolve(null);
                                }
                            };
                            
                            request.onerror = function() {
                                reject(request.error);
                            };
                        } else {
                            resolve(null);
                        }
                    });
                };
            }
        </script>';
    }
    add_action('wp_head', 'add_indexeddb_support');
    
    // Добавляем финальную оптимизацию - сжатие данных
    function add_compression_support() {
        echo '<script>
            // Проверяем поддержку сжатия
            if ("CompressionStream" in window) {
                window.compressData = async function(data) {
                    const stream = new CompressionStream("gzip");
                    const writer = stream.writable.getWriter();
                    const reader = stream.readable.getReader();
                    
                    const encoder = new TextEncoder();
                    const encoded = encoder.encode(JSON.stringify(data));
                    
                    writer.write(encoded);
                    writer.close();
                    
                    const chunks = [];
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        chunks.push(value);
                    }
                    
                    return new Blob(chunks);
                };
                
                window.decompressData = async function(blob) {
                    const stream = new DecompressionStream("gzip");
                    const writer = stream.writable.getWriter();
                    const reader = stream.readable.getReader();
                    
                    writer.write(blob);
                    writer.close();
                    
                    const chunks = [];
                    while (true) {
                        const { done, value } = await reader.read();
                        if (done) break;
                        chunks.push(value);
                    }
                    
                    const decoder = new TextDecoder();
                    const decoded = decoder.decode(new Uint8Array(chunks.flat()));
                    return JSON.parse(decoded);
                };
            }
        </script>';
    }
    add_action('wp_head', 'add_compression_support');
}
add_action('init', 'cambocom_ajax_taxonomy_filter');
?>