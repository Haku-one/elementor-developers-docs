// ИСПРАВЛЕНИЕ ОШИБКИ ElementorPlugin
// Замените эти строки в вашем коде:

// БЫЛО (строка 389):
$template_content = ElementorPlugin::$instance->frontend->get_builder_content_for_display($template_id);

// СТАЛО:
$template_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id);

// Также исправьте в REST API функции:
// БЫЛО:
$template_content = ElementorPlugin::$instance->frontend->get_builder_content_for_display($template_id);

// СТАЛО:
$template_content = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($template_id);