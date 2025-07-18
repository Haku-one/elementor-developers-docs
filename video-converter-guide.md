# Исправление проблем с видео в Firefox

## Причины проблемы

1. **Неподдерживаемый кодек** - Firefox требует H.264 кодек с определенными настройками
2. **Неправильные параметры кодирования** - битрейт, профиль кодека
3. **Проблемы с заголовками HTTP** - CORS, MIME-типы
4. **Политики автовоспроизведения** - Firefox строже Chrome

## Быстрое решение

### 1. Конвертация видео в совместимый формат

```bash
# Используя FFmpeg для создания Firefox-совместимого MP4
ffmpeg -i img_6412.mp4 \
  -c:v libx264 \
  -profile:v baseline \
  -level 3.0 \
  -pix_fmt yuv420p \
  -c:a aac \
  -ac 2 \
  -b:a 128k \
  -movflags +faststart \
  img_6412_firefox.mp4

# Создание WebM версии (отличная поддержка в Firefox)
ffmpeg -i img_6412.mp4 \
  -c:v libvpx-vp9 \
  -crf 30 \
  -b:v 0 \
  -c:a libopus \
  -b:a 128k \
  img_6412.webm

# Создание OGV версии (полная совместимость с Firefox)
ffmpeg -i img_6412.mp4 \
  -c:v libtheora \
  -q:v 7 \
  -c:a libvorbis \
  -q:a 4 \
  img_6412.ogv
```

### 2. Проверка .htaccess для правильных MIME-типов

```apache
# Добавьте в .htaccess
AddType video/mp4 .mp4
AddType video/webm .webm
AddType video/ogg .ogv

# Настройки для кеширования видео
<FilesMatch "\.(mp4|webm|ogv)$">
    Header set Cache-Control "max-age=31536000, public"
    Header set Access-Control-Allow-Origin "*"
</FilesMatch>
```

### 3. PHP скрипт для диагностики

```php
<?php
// video-diagnostic.php
$videoPath = '/wp-content/uploads/2025/06/img_6412.mp4';
$fullPath = $_SERVER['DOCUMENT_ROOT'] . $videoPath;

echo "<h2>Диагностика видео</h2>";

// Проверка существования файла
if (file_exists($fullPath)) {
    echo "✅ Файл существует<br>";
    echo "📏 Размер: " . formatBytes(filesize($fullPath)) . "<br>";
    
    // Проверка MIME-типа
    $mimeType = mime_content_type($fullPath);
    echo "📋 MIME-тип: " . $mimeType . "<br>";
    
    // Проверка доступности по HTTP
    $url = "https://cambocom.com" . $videoPath;
    $headers = get_headers($url, 1);
    echo "🌐 HTTP статус: " . $headers[0] . "<br>";
    
    if (isset($headers['Content-Type'])) {
        echo "📄 HTTP Content-Type: " . $headers['Content-Type'] . "<br>";
    }
    
} else {
    echo "❌ Файл не найден: " . $fullPath;
}

// Проверка доступности FFmpeg
$ffmpegCheck = shell_exec('ffmpeg -version 2>&1');
if ($ffmpegCheck) {
    echo "<br>✅ FFmpeg доступен для конвертации";
} else {
    echo "<br>❌ FFmpeg не установлен";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
?>
```

## Пошаговое решение

### Шаг 1: Замените HTML код видео

```html
<video class="elementor-background-video-hosted" 
       autoplay 
       muted 
       playsinline 
       loop 
       preload="metadata">
    <source src="/wp-content/uploads/2025/06/img_6412.mp4" type="video/mp4">
    <source src="/wp-content/uploads/2025/06/img_6412.webm" type="video/webm">
    <source src="/wp-content/uploads/2025/06/img_6412.ogv" type="video/ogg">
</video>
```

### Шаг 2: Добавьте JavaScript обработку

```javascript
// Специально для Firefox
if (navigator.userAgent.includes('Firefox')) {
    const video = document.querySelector('.elementor-background-video-hosted');
    
    video.addEventListener('loadedmetadata', function() {
        // Firefox требует явного вызова play()
        this.play().catch(console.error);
    });
    
    // Fallback на poster изображение
    video.addEventListener('error', function() {
        this.style.display = 'none';
        this.parentElement.style.backgroundImage = 
            'url(/wp-content/uploads/2025/06/img_6412_poster.jpg)';
    });
}
```

### Шаг 3: Создайте poster изображение

```bash
# Извлеките кадр из видео для poster
ffmpeg -i img_6412.mp4 -ss 00:00:01 -vframes 1 -q:v 2 img_6412_poster.jpg
```

## Альтернативные решения

### 1. Используйте Cloudflare Stream
- Автоматическая оптимизация для всех браузеров
- Адаптивное качество
- Глобальная CDN

### 2. YouTube/Vimeo embed
```html
<iframe src="https://www.youtube.com/embed/VIDEO_ID?autoplay=1&mute=1&loop=1&playlist=VIDEO_ID" 
        frameborder="0" 
        allow="autoplay; encrypted-media">
</iframe>
```

### 3. CSS анимация как fallback
```css
.video-fallback {
    background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
    background-size: 400% 400%;
    animation: gradient 15s ease infinite;
}

@keyframes gradient {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
```

## Проверка результата

1. Откройте консоль Firefox (F12)
2. Перезагрузите страницу
3. Проверьте ошибки в консоли
4. Посмотрите во вкладке Network загружается ли видео

## Контрольный чек-лист

- [ ] Видео сконвертировано в baseline профиль H.264
- [ ] Добавлены альтернативные форматы (WebM, OGV)  
- [ ] Настроены правильные MIME-типы
- [ ] Добавлен poster фоллбэк
- [ ] Протестировано в Firefox
- [ ] Добавлена диагностика в консоль