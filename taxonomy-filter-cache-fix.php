<?php
// Исправленная функция для AJAX фильтра таксономии с правильной очисткой кэша
function cambocom_ajax_taxonomy_filter_fixed() {
    // Создаем шорткод [ajax_taxonomy_filter]
    function ajax_taxonomy_filter_shortcode($atts) {
        $atts = shortcode_atts(array(
            'taxonomy' => 'categories',
            'limit' => 10,
            'orderby' => 'count',
            'order' => 'DESC',
            'hide_empty' => true,
            'target_id' => '',
            'post_type' => 'blog',
        ), $atts);
        
        $taxonomy = sanitize_text_field($atts['taxonomy']);
        $limit = intval($atts['limit']);
        $orderby = sanitize_text_field($atts['orderby']);
        $order = sanitize_text_field($atts['order']);
        $hide_empty = filter_var($atts['hide_empty'], FILTER_VALIDATE_BOOLEAN);
        $target_id = sanitize_text_field($atts['target_id']);
        $post_type = sanitize_text_field($atts['post_type']);
        
        // ИСПРАВЛЕНИЕ: Добавляем версию кэша на основе последнего изменения терминов
        $last_modified = get_option('taxonomy_' . $taxonomy . '_last_modified', time());
        
        // Получаем все термины таксономии с улучшенным кэшированием
        $cache_key = 'taxonomy_terms_' . md5(serialize($atts) . $last_modified);
        $terms = get_transient($cache_key);
        
        if (false === $terms) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'orderby' => $orderby,
                'order' => $order,
                'hide_empty' => $hide_empty,
            ));
            
            // Кэшируем на 30 минут (вместо часа для быстрого обновления)
            set_transient($cache_key, $terms, 30 * MINUTE_IN_SECONDS);
        }
        
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
    
    // ИСПРАВЛЕНИЕ: Улучшенная очистка кэша при изменении терминов
    function clear_taxonomy_filter_cache($term_id = null, $taxonomy = null) {
        // Очищаем все транзиенты, связанные с таксономией
        global $wpdb;
        
        if ($taxonomy) {
            // Обновляем время последнего изменения для конкретной таксономии
            update_option('taxonomy_' . $taxonomy . '_last_modified', time());
            
            // Очищаем кэш для конкретной таксономии
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name LIKE %s", 
                '%_transient_taxonomy_terms_%'));
        } else {
            // Очищаем весь кэш фильтров если таксономия не указана
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_taxonomy_terms_%'");
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_filter_%'");
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_rest_filter_%'");
        }
        
        // Также очищаем кэш объектов WordPress
        wp_cache_flush();
        
        // Логируем для отладки
        error_log('Taxonomy cache cleared for: ' . ($taxonomy ?: 'all taxonomies'));
    }
    
    // Добавляем хуки для очистки кэша при изменении терминов
    add_action('created_term', function($term_id, $taxonomy_id, $taxonomy) {
        clear_taxonomy_filter_cache($term_id, $taxonomy);
    }, 10, 3);
    
    add_action('edited_term', function($term_id, $taxonomy_id, $taxonomy) {
        clear_taxonomy_filter_cache($term_id, $taxonomy);
    }, 10, 3);
    
    add_action('delete_term', function($term_id, $taxonomy_id, $taxonomy) {
        clear_taxonomy_filter_cache($term_id, $taxonomy);
    }, 10, 3);
    
    // Также очищаем кэш при изменении постов
    add_action('save_post', function($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        
        // Получаем все таксономии для типа поста
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type);
        
        foreach ($taxonomies as $taxonomy) {
            clear_taxonomy_filter_cache(null, $taxonomy);
        }
    });
    
    add_action('deleted_post', function($post_id) {
        // Получаем все таксономии для типа поста
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type);
        
        foreach ($taxonomies as $taxonomy) {
            clear_taxonomy_filter_cache(null, $taxonomy);
        }
    });
    
    // ИСПРАВЛЕНИЕ: Добавляем принудительную очистку кэша через админку
    function add_clear_cache_button() {
        add_action('admin_bar_menu', function($wp_admin_bar) {
            if (current_user_can('manage_options')) {
                $wp_admin_bar->add_node(array(
                    'id' => 'clear_taxonomy_cache',
                    'title' => 'Очистить кэш фильтров',
                    'href' => admin_url('admin.php?page=clear-taxonomy-cache'),
                ));
            }
        }, 100);
    }
    add_action('init', 'add_clear_cache_button');
    
    // Добавляем страницу в админке для очистки кэша
    function add_clear_cache_admin_page() {
        add_action('admin_menu', function() {
            add_management_page(
                'Очистка кэша фильтров',
                'Очистка кэша фильтров',
                'manage_options',
                'clear-taxonomy-cache',
                'clear_cache_admin_page_content'
            );
        });
    }
    add_action('init', 'add_clear_cache_admin_page');
    
    function clear_cache_admin_page_content() {
        if (isset($_POST['clear_cache'])) {
            clear_taxonomy_filter_cache();
            echo '<div class="notice notice-success"><p>Кэш фильтров очищен!</p></div>';
        }
        
        echo '<div class="wrap">';
        echo '<h1>Очистка кэша фильтров таксономии</h1>';
        echo '<form method="post">';
        echo '<p>Нажмите кнопку ниже, чтобы очистить весь кэш фильтров. Это поможет, если новые категории не отображаются.</p>';
        echo '<input type="submit" name="clear_cache" class="button-primary" value="Очистить кэш" />';
        echo '</form>';
        echo '</div>';
    }
    
    // ИСПРАВЛЕНИЕ: Добавляем AJAX endpoint для принудительного обновления
    function add_refresh_terms_endpoint() {
        add_action('wp_ajax_refresh_taxonomy_terms', 'ajax_refresh_taxonomy_terms');
        add_action('wp_ajax_nopriv_refresh_taxonomy_terms', 'ajax_refresh_taxonomy_terms');
    }
    add_action('init', 'add_refresh_terms_endpoint');
    
    function ajax_refresh_taxonomy_terms() {
        // Проверяем nonce для безопасности
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'filter_loop_grid_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';
        
        if (empty($taxonomy)) {
            wp_send_json_error('Taxonomy not specified');
            return;
        }
        
        // Очищаем кэш для таксономии
        clear_taxonomy_filter_cache(null, $taxonomy);
        
        // Получаем свежие данные
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'orderby' => 'count',
            'order' => 'DESC',
            'hide_empty' => true,
        ));
        
        if (is_wp_error($terms)) {
            wp_send_json_error($terms->get_error_message());
            return;
        }
        
        $terms_data = array();
        foreach ($terms as $term) {
            $terms_data[] = array(
                'slug' => $term->slug,
                'name' => $term->name,
                'count' => $term->count,
            );
        }
        
        wp_send_json_success($terms_data);
    }
    
    // Регистрируем скрипт для AJAX с добавлением функции обновления
    function register_filter_script() {
        wp_register_script('taxonomy-filter', '', array('jquery'), '1.1', true);
        wp_localize_script('taxonomy-filter', 'taxonomy_filter_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('filter_loop_grid_nonce'),
            'rest_url' => rest_url('taxonomy-filter/v1/filter'),
        ));
        wp_enqueue_script('taxonomy-filter');
        
        // Встраиваем улучшенный JavaScript
        add_action('wp_footer', 'add_improved_filter_script');
    }
    add_action('wp_enqueue_scripts', 'register_filter_script');
    
    // Улучшенный JavaScript с функцией обновления терминов
    function add_improved_filter_script() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Кэш для хранения результатов запросов
            const responseCache = {};
            
            // Функция дебаунса
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
            
            // НОВОЕ: Функция для обновления списка терминов
            function refreshTermsList(taxonomy) {
                $.ajax({
                    url: taxonomy_filter_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'refresh_taxonomy_terms',
                        nonce: taxonomy_filter_vars.nonce,
                        taxonomy: taxonomy
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Terms refreshed:', response.data);
                            
                            // Обновляем список терминов в DOM
                            const $filterList = $('.work-filter__list[data-taxonomy="' + taxonomy + '"]');
                            if ($filterList.length) {
                                updateTermsInDOM($filterList, response.data, taxonomy);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Failed to refresh terms:', error);
                    }
                });
            }
            
            // НОВОЕ: Функция для обновления терминов в DOM
            function updateTermsInDOM($container, terms, taxonomy) {
                // Сохраняем кнопку "Все" и "Показать еще"
                const $allButton = $container.find('.filter-item[data-term=""]');
                const $showMoreButton = $container.find('.js-show-more-filter');
                
                // Удаляем все термины, кроме кнопки "Все"
                $container.find('.filter-item[data-term!=""]').remove();
                
                // Добавляем обновленные термины
                let count = 0;
                const limit = 10; // Используем тот же лимит
                
                $.each(terms, function(index, term) {
                    count++;
                    const isHidden = count > limit ? ' style="display: none;" class="work-filter__item filter-item hidden-term"' : ' class="work-filter__item filter-item"';
                    
                    const termHtml = '<a' + isHidden + ' href="javascript:void(0);" data-term="' + term.slug + '" data-taxonomy="' + taxonomy + '">' +
                        term.name + '<span>' + Number(term.count).toLocaleString() + '</span></a>';
                    
                    if ($showMoreButton.length && count > limit) {
                        $showMoreButton.before(termHtml);
                    } else {
                        $container.append(termHtml);
                    }
                });
                
                // Показываем/скрываем кнопку "Показать еще"
                if (terms.length > limit) {
                    if (!$showMoreButton.length) {
                        $container.append('<button class="work-filter__show-more js-show-more-filter" type="button">' +
                            '<svg width="9" height="9"><use xlink:href="#plus"></use></svg>' +
                            '<b>Показать ещё</b><span>...</span></button>');
                    }
                } else {
                    $showMoreButton.remove();
                }
                
                // Переназначаем обработчики событий для новых элементов
                bindFilterEvents();
            }
            
            // НОВОЕ: Функция для переназначения обработчиков событий
            function bindFilterEvents() {
                // Удаляем старые обработчики
                $('.filter-item').off('click.taxonomyFilter');
                $('.js-show-more-filter').off('click.taxonomyFilter');
                
                // Добавляем новые обработчики
                $('.filter-item').on('click.taxonomyFilter', debounce(function(e) {
                    e.preventDefault();
                    handleFilterClick($(this));
                }, 300));
                
                $('.js-show-more-filter').on('click.taxonomyFilter', function() {
                    $(this).closest('.work-filter__list').find('.hidden-term').show();
                    $(this).hide();
                });
            }
            
            // Выносим логику клика в отдельную функцию
            function handleFilterClick($this) {
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
            }
            
            // Функция для инициализации счетчиков просмотров
            function initViewCounters() {
                if (typeof window.initPostViewCounters === 'function') {
                    window.initPostViewCounters();
                }
                
                $('svg use').each(function() {
                    const href = $(this).attr('xlink:href');
                    if (href && href === '#eye') {
                        // Иконка уже правильная
                    }
                });
            }
            
            // НОВОЕ: Автоматическое обновление терминов каждые 5 минут
            function autoRefreshTerms() {
                $('.work-filter__list').each(function() {
                    const $list = $(this);
                    const taxonomy = $list.find('.filter-item').first().data('taxonomy');
                    
                    if (taxonomy) {
                        setInterval(function() {
                            refreshTermsList(taxonomy);
                        }, 5 * 60 * 1000); // 5 минут
                    }
                });
            }
            
            // НОВОЕ: Кнопка принудительного обновления
            function addRefreshButton() {
                $('.work-filter__list').each(function() {
                    const $list = $(this);
                    if (!$list.find('.refresh-terms-btn').length) {
                        const $refreshBtn = $('<button class="refresh-terms-btn" title="Обновить категории" style="margin-left: 10px; background: none; border: 1px solid #ccc; border-radius: 3px; padding: 5px; cursor: pointer;">🔄</button>');
                        
                        $refreshBtn.on('click', function(e) {
                            e.preventDefault();
                            const taxonomy = $list.find('.filter-item').first().data('taxonomy');
                            if (taxonomy) {
                                $(this).css('opacity', '0.5');
                                refreshTermsList(taxonomy);
                                setTimeout(() => {
                                    $(this).css('opacity', '1');
                                }, 1000);
                            }
                        });
                        
                        $list.append($refreshBtn);
                    }
                });
            }
            
            // Инициализация
            bindFilterEvents();
            
            // Добавляем кнопку обновления только в режиме разработки или для админов
            <?php if (current_user_can('manage_options') || WP_DEBUG): ?>
            addRefreshButton();
            <?php endif; ?>
            
            // Автоматическое обновление (только если включено в настройках)
            // autoRefreshTerms(); // Раскомментируйте если нужно автообновление
        });
        </script>
        <?php
    }
    
    // Остальной код остается без изменений...
    // (здесь должны быть все остальные функции из оригинального кода)
}

// ВАЖНО: Замените старую функцию на новую
// Закомментируйте или удалите вызов старой функции и используйте новую:
add_action('init', 'cambocom_ajax_taxonomy_filter_fixed');

// БЫСТРОЕ ИСПРАВЛЕНИЕ: Принудительная очистка кэша
function force_clear_taxonomy_cache_now() {
    global $wpdb;
    
    // Очищаем все транзиенты связанные с фильтрами
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_taxonomy_terms_%'");
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_filter_%'");
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '%_transient_rest_filter_%'");
    
    // Обновляем время последнего изменения для всех таксономий
    $taxonomies = get_taxonomies();
    foreach ($taxonomies as $taxonomy) {
        update_option('taxonomy_' . $taxonomy . '_last_modified', time());
    }
    
    // Очищаем кэш объектов
    wp_cache_flush();
    
    error_log('Force cleared all taxonomy cache');
}

// Вызовите эту функцию ОДИН РАЗ для очистки кэша:
// force_clear_taxonomy_cache_now();
?>