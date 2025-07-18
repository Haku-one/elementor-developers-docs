<?php
/**
 * Диагностика видео для Firefox
 * Поместите этот файл в корень сайта и откройте в браузере
 */

$videoPath = '/wp-content/uploads/2025/06/img_6412.mp4';
$fullPath = $_SERVER['DOCUMENT_ROOT'] . $videoPath;
$siteUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Диагностика видео Firefox</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .test-video { margin: 20px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🎥 Диагностика видео для Firefox</h1>
    
    <?php
    // Проверка существования файла
    echo "<h2>📁 Проверка файла</h2>";
    if (file_exists($fullPath)) {
        echo "<div class='status success'>✅ Файл существует: " . $fullPath . "</div>";
        echo "<div class='status success'>📏 Размер: " . formatBytes(filesize($fullPath)) . "</div>";
        
        // Проверка MIME-типа
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($fullPath);
            echo "<div class='status success'>📋 MIME-тип: " . $mimeType . "</div>";
        }
        
        // Проверка прав доступа
        if (is_readable($fullPath)) {
            echo "<div class='status success'>🔓 Файл доступен для чтения</div>";
        } else {
            echo "<div class='status error'>🔒 Файл недоступен для чтения</div>";
        }
        
    } else {
        echo "<div class='status error'>❌ Файл не найден: " . $fullPath . "</div>";
    }
    
    // Проверка HTTP доступности
    echo "<h2>🌐 Проверка HTTP доступности</h2>";
    $videoUrl = $siteUrl . $videoPath;
    $headers = @get_headers($videoUrl, 1);
    
    if ($headers) {
        $statusCode = substr($headers[0], 9, 3);
        if ($statusCode == '200') {
            echo "<div class='status success'>✅ HTTP статус: " . $headers[0] . "</div>";
        } else {
            echo "<div class='status error'>❌ HTTP статус: " . $headers[0] . "</div>";
        }
        
        if (isset($headers['Content-Type'])) {
            $contentType = is_array($headers['Content-Type']) ? 
                $headers['Content-Type'][0] : $headers['Content-Type'];
            echo "<div class='status success'>📄 Content-Type: " . $contentType . "</div>";
        }
        
        if (isset($headers['Content-Length'])) {
            $contentLength = is_array($headers['Content-Length']) ? 
                $headers['Content-Length'][0] : $headers['Content-Length'];
            echo "<div class='status success'>📏 Content-Length: " . formatBytes($contentLength) . "</div>";
        }
    } else {
        echo "<div class='status error'>❌ Не удалось получить HTTP заголовки</div>";
    }
    
    // Проверка браузера
    echo "<h2>🌐 Информация о браузере</h2>";
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Неизвестно';
    echo "<div class='status success'>🖥️ User Agent: " . htmlspecialchars($userAgent) . "</div>";
    
    if (strpos($userAgent, 'Firefox') !== false) {
        echo "<div class='status warning'>🦊 Firefox обнаружен - применяем специальные настройки</div>";
    }
    
    // Проверка .htaccess
    echo "<h2>⚙️ Рекомендуемые настройки .htaccess</h2>";
    $htaccessPath = $_SERVER['DOCUMENT_ROOT'] . '/.htaccess';
    if (file_exists($htaccessPath)) {
        $htaccessContent = file_get_contents($htaccessPath);
        if (strpos($htaccessContent, 'AddType video/mp4') !== false) {
            echo "<div class='status success'>✅ MIME-типы для видео настроены</div>";
        } else {
            echo "<div class='status warning'>⚠️ MIME-типы для видео не найдены</div>";
            echo "<div class='status warning'>Добавьте в .htaccess:</div>";
            echo "<pre>AddType video/mp4 .mp4
AddType video/webm .webm
AddType video/ogg .ogv</pre>";
        }
    } else {
        echo "<div class='status warning'>⚠️ .htaccess не найден</div>";
    }
    
    // Тест воспроизведения
    echo "<h2>🎬 Тест воспроизведения</h2>";
    if (file_exists($fullPath)) {
        echo "<div class='test-video'>";
        echo "<video width='400' height='300' controls muted>";
        echo "<source src='" . $videoUrl . "' type='video/mp4'>";
        echo "Ваш браузер не поддерживает видео.";
        echo "</video>";
        echo "</div>";
        
        echo "<div class='status warning'>💡 Если видео не воспроизводится, проверьте консоль браузера (F12)</div>";
    }
    
    // JavaScript диагностика
    echo "<h2>🔧 JavaScript диагностика</h2>";
    ?>
    
    <div id="js-diagnostic"></div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const diagnosticDiv = document.getElementById('js-diagnostic');
        let results = [];
        
        // Проверка поддержки форматов
        const video = document.createElement('video');
        const formats = {
            'MP4 (H.264)': video.canPlayType('video/mp4; codecs="avc1.42E01E"'),
            'WebM (VP9)': video.canPlayType('video/webm; codecs="vp9"'),
            'OGG (Theora)': video.canPlayType('video/ogg; codecs="theora"')
        };
        
        results.push('<h3>🎵 Поддержка форматов</h3>');
        for (const [format, support] of Object.entries(formats)) {
            const level = support === 'probably' ? 'success' : 
                         support === 'maybe' ? 'warning' : 'error';
            const icon = support === 'probably' ? '✅' : 
                        support === 'maybe' ? '⚠️' : '❌';
            results.push(`<div class="status ${level}">${icon} ${format}: ${support || 'не поддерживается'}</div>`);
        }
        
        // Проверка автовоспроизведения
        results.push('<h3>🔊 Проверка автовоспроизведения</h3>');
        const testVideo = document.createElement('video');
        testVideo.muted = true;
        testVideo.src = '<?php echo $videoUrl; ?>';
        
        testVideo.play().then(() => {
            results.push('<div class="status success">✅ Автовоспроизведение разрешено</div>');
        }).catch((error) => {
            results.push(`<div class="status error">❌ Автовоспроизведение заблокировано: ${error.message}</div>`);
            results.push('<div class="status warning">💡 Попробуйте взаимодействовать со страницей перед воспроизведением</div>');
        });
        
        // Информация о браузере
        results.push('<h3>🌐 Информация о браузере</h3>');
        results.push(`<div class="status success">🖥️ User Agent: ${navigator.userAgent}</div>`);
        results.push(`<div class="status success">📱 Platform: ${navigator.platform}</div>`);
        
        // Проверка сетевого соединения
        if ('connection' in navigator) {
            const conn = navigator.connection;
            results.push(`<div class="status success">📶 Тип соединения: ${conn.effectiveType || 'неизвестно'}</div>`);
        }
        
        diagnosticDiv.innerHTML = results.join('');
    });
    </script>
    
    <?php
    echo "<h2>🛠️ Рекомендации по исправлению</h2>";
    
    if (!file_exists($fullPath)) {
        echo "<div class='status error'>1. Загрузите видеофайл на сервер</div>";
    }
    
    echo "<div class='status warning'>2. Сконвертируйте видео в совместимый формат:</div>";
    echo "<pre>ffmpeg -i img_6412.mp4 -c:v libx264 -profile:v baseline -level 3.0 -pix_fmt yuv420p -c:a aac -movflags +faststart img_6412_fixed.mp4</pre>";
    
    echo "<div class='status warning'>3. Создайте альтернативные форматы:</div>";
    echo "<pre>ffmpeg -i img_6412.mp4 -c:v libvpx-vp9 -crf 30 -c:a libopus img_6412.webm</pre>";
    
    echo "<div class='status warning'>4. Используйте множественные источники в HTML:</div>";
    echo "<pre>&lt;video autoplay muted loop&gt;
    &lt;source src=\"video.mp4\" type=\"video/mp4\"&gt;
    &lt;source src=\"video.webm\" type=\"video/webm\"&gt;
&lt;/video&gt;</pre>";
    
    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    ?>
    
    <footer style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666;">
        <p>💡 <strong>Совет:</strong> Для лучшей производительности используйте CDN и сжатие видео</p>
        <p>🔧 Этот скрипт можно удалить после решения проблемы</p>
    </footer>
</body>
</html>