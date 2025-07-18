<?php
// ИСПРАВЛЕНИЕ: Убираем кэш терминов
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
    
    // ИСПРАВЛЕНИЕ: Убираем кэширование - получаем термины напрямую
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
?>