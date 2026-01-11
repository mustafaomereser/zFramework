<?php

use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Config;
use zFramework\Core\Helpers\Http;

function getStackTrace($stackTrace)
{
    $output = '';

    foreach ($stackTrace as $trace) {
        $functionName = '';

        if (!empty($trace['class'])) {
            $functionName = $trace['class'] . $trace['type'] . $trace['function'];
        } elseif (!empty($trace['function'])) {
            $functionName = $trace['function'] . '()';
        }

        $output .= '<div class="stack-item" data-index="' . $trace['index'] . '">';
        $output .= '<div class="stack-header-content">';
        $output .= '<div class="stack-file">' . basename($trace['file']) . '</div>';
        $output .= '<div class="stack-line">line ' . $trace['line'] . '</div>';
        $output .= '</div>';
        if ($functionName) {
            $output .= '<div class="stack-function">' . htmlspecialchars($functionName) . '</div>';
        }
        $output .= '<div class="stack-path">' . $trace['file'] . '</div>';
        $output .= '</div>';
    }

    return $output;
}

function getCodeSnippets($stackTrace)
{
    $output = '';

    foreach ($stackTrace as $trace) {
        $output .= '<div class="code-snippet" data-index="' . $trace['index'] . '">';

        // Dosya içeriğini oku
        if (file_exists($trace['file'])) {
            $lines = file($trace['file']);
            $errorLine = $trace['line'];
            $startLine = max(1, $errorLine - 15);
            $endLine = min(count($lines), $errorLine + 15);

            $output .= '<div class="code-header">';
            $output .= '<div class="code-header-info">';
            $output .= '<span class="code-file">' . $trace['file'] . ':' . $errorLine . '</span>';

            // Hata detaylarını ekle
            if (!empty($trace['function'])) {
                $functionInfo = '';
                if (!empty($trace['class'])) {
                    $functionInfo = $trace['class'] . $trace['type'] . $trace['function'] . '()';
                } else {
                    $functionInfo = $trace['function'] . '()';
                }
                $output .= '<div class="function-info">📍 ' . htmlspecialchars($functionInfo) . '</div>';
            }

            // Argümanları göster (eğer varsa)
            if (!empty($trace['args'])) {
                $output .= '<div class="args-info">🔧 Argümanlar: ';
                $argStrings = [];
                foreach ($trace['args'] as $arg) {
                    if (is_string($arg)) {
                        $argStrings[] = $arg;
                    } elseif (is_numeric($arg)) {
                        $argStrings[] = $arg;
                    } elseif (is_bool($arg)) {
                        $argStrings[] = $arg ? 'true' : 'false';
                    } elseif (is_null($arg)) {
                        $argStrings[] = 'null';
                    } elseif (is_array($arg)) {
                        $argStrings[] = "<pre>" . print_r($arg, true) . "</pre>";
                    } elseif (is_object($arg)) {
                        $argStrings[] = get_class($arg);
                    } else {
                        $argStrings[] = gettype($arg);
                    }
                }
                $output .= implode(', ', $argStrings) . '</div>';
            }

            $output .= '</div>';
            $output .= '<button class="ide-button" onclick="goIDE(\'' . str_replace("\\", "/", $trace['file']) . '\', ' . $errorLine . ')">Open in IDE</button>';
            $output .= '</div>';

            $output .= '<div class="code-content">';
            for ($i = $startLine; $i <= $endLine; $i++) {
                $lineContent = isset($lines[$i - 1]) ? rtrim($lines[$i - 1]) : '';
                $isErrorLine = $i === $errorLine;
                $lineClass = $isErrorLine ? 'error-line' : '';

                $output .= '<div class="code-line ' . $lineClass . '">';
                $output .= '<span class="line-number">' . $i . '</span>';
                $output .= '<span class="line-content">' . htmlspecialchars($lineContent) . '</span>';
                $output .= '</div>';
            }
            $output .= '</div>';
        } else {
            $output .= '<div class="code-header">';
            $output .= '<span class="code-file">' . $trace['file'] . ':' . $trace['line'] . '</span>';
            $output .= '</div>';
            $output .= '<div class="code-content">';
            $output .= '<div class="no-file">Dosya bulunamadı</div>';
            $output .= '</div>';
        }

        $output .= '</div>';
    }

    return $output;
}

function getErrorDetails($message, $file, $line)
{
    $details = [];

    // Hata türünü belirle
    if (strpos($message, 'SQLSTATE') !== false) {
        $details['type'] = 'Database Error';
        $details['icon'] = '🗄️';
        $details['description'] = 'Veritabanı sorgusu sırasında bir hata oluştu. SQL syntax\'ınızı kontrol edin.';
    } elseif (strpos($message, 'Fatal error') !== false) {
        $details['type'] = 'Fatal Error';
        $details['icon'] = '💀';
        $details['description'] = 'Kritik bir hata oluştu ve script çalışması durdu.';
    } elseif (strpos($message, 'Parse error') !== false) {
        $details['type'] = 'Parse Error';
        $details['icon'] = '📝';
        $details['description'] = 'PHP kodu parse edilemedi. Syntax hatası var.';
    } elseif (strpos($message, 'Warning') !== false) {
        $details['type'] = 'Warning';
        $details['icon'] = '⚠️';
        $details['description'] = 'Bir uyarı oluştu ama script çalışmaya devam etti.';
    } else {
        $details['type'] = 'Error';
        $details['icon'] = '❌';
        $details['description'] = 'Bir hata oluştu.';
    }

    return $details;
}


function errorHandler($data)
{
    ob_end_clean();

    ob_start();
    $data = array_values((array) $data);
    $message = $data[0];

    $err_code = $data[2];
    $errorDetails = getErrorDetails($message, $data[3], $data[4]);

    // Stack trace'i düzgün şekilde oluştur ve aynı dosya/satırları birleştir
    $stackTrace = [];
    $seenTraces = [];

    // Ana hatayı ekle - ilk stack trace item'ından argüman bilgilerini al
    $mainKey = $data[3] . ':' . $data[4];
    if (!isset($seenTraces[$mainKey])) {
        $mainArgs = [];
        $mainFunction = '';
        $mainClass = '';
        $mainType = '';

        // İlk stack trace item'ından bilgileri al
        if (!empty($data[5]) && isset($data[5][0])) {
            $firstTrace = $data[5][0];
            $mainFunction = isset($firstTrace['function']) ? $firstTrace['function'] : '';
            $mainClass = isset($firstTrace['class']) ? $firstTrace['class'] : '';
            $mainType = isset($firstTrace['type']) ? $firstTrace['type'] : '';
            $mainArgs = isset($firstTrace['args']) ? $firstTrace['args'] : [];
        }

        $stackTrace[] = [
            'file' => $data[3],
            'line' => $data[4],
            'function' => $mainFunction,
            'class' => $mainClass,
            'type' => $mainType,
            'args' => $mainArgs,
            'index' => count($stackTrace)
        ];
        $seenTraces[$mainKey] = true;
    }

    // Diğer stack trace öğelerini ekle (aynı dosya/satırları atla)
    foreach ($data[5] as $error) {
        if (isset($error['file']) && isset($error['line'])) {
            $key = $error['file'] . ':' . $error['line'];
            if (!isset($seenTraces[$key])) {
                $stackTrace[] = [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'function' => isset($error['function']) ? $error['function'] : '',
                    'class' => isset($error['class']) ? $error['class'] : '',
                    'type' => isset($error['type']) ? $error['type'] : '',
                    'args' => isset($error['args']) ? $error['args'] : [],
                    'index' => count($stackTrace)
                ];
                $seenTraces[$key] = true;
            }
        }
    }
?>

    <!DOCTYPE html>
    <html lang="tr">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Whoops! Bir hata oluştu</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --bg-primary: #0f172a;
                --bg-secondary: #1e293b;
                --bg-tertiary: #334155;
                --text-primary: #e2e8f0;
                --text-secondary: #94a3b8;
                --text-muted: #64748b;
                --accent-primary: #ef4444;
                --accent-secondary: #3b82f6;
                --border-color: #334155;
                --error-bg: rgba(239, 68, 68, 0.1);
                --error-border: #ef4444;
            }

            [data-theme="light"] {
                --bg-primary: #ffffff;
                --bg-secondary: #f8fafc;
                --bg-tertiary: #e2e8f0;
                --text-primary: #1e293b;
                --text-secondary: #475569;
                --text-muted: #64748b;
                --accent-primary: #dc2626;
                --accent-secondary: #2563eb;
                --border-color: #e2e8f0;
                --error-bg: rgba(220, 38, 38, 0.1);
                --error-border: #dc2626;
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: var(--bg-primary);
                color: var(--text-primary);
                line-height: 1.6;
                overflow-x: hidden;
            }

            .error-container {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }

            .error-header {
                background: linear-gradient(135deg, var(--accent-primary) 0%, #dc2626 100%);
                padding: 2rem 0;
                position: relative;
                overflow: hidden;
            }

            .error-header::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
                opacity: 0.3;
            }

            .error-content {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 2rem;
                position: relative;
                z-index: 1;
            }

            .error-title {
                font-size: 2.5rem;
                font-weight: 700;
                color: white;
                margin-bottom: 0.5rem;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
                display: flex;
                align-items: center;
                gap: 1rem;
            }

            .error-subtitle {
                font-size: 1.1rem;
                color: rgba(255, 255, 255, 0.9);
                font-weight: 400;
                margin-bottom: 1rem;
            }

            .error-details {
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 12px;
                padding: 1rem 1.5rem;
                border: 1px solid rgba(255, 255, 255, 0.2);
                margin-bottom: 10px;
            }

            .error-type {
                font-size: 0.9rem;
                color: rgba(255, 255, 255, 0.8);
                margin-bottom: 0.5rem;
            }

            .error-description {
                font-size: 0.9rem;
                color: rgba(255, 255, 255, 0.7);
            }

            .error-location {
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 12px;
                padding: 1rem 1.5rem;
                border: 1px solid rgba(255, 255, 255, 0.2);
                display: inline-flex;
                align-items: center;
                gap: 0.75rem;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .error-location:hover {
                background: rgba(255, 255, 255, 0.15);
                transform: translateY(-1px);
            }

            .error-location-icon {
                width: 20px;
                height: 20px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
            }

            .error-location-text {
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.9rem;
            }

            .main-content {
                flex: 1;
                display: flex;
                max-width: 95%;
                margin: 0 auto;
                width: 100%;
            }

            .stack-trace {
                width: 400px;
                min-width: 400px;
                background: var(--bg-secondary);
                border-right: 1px solid var(--border-color);
                overflow-y: auto;
                max-height: calc(100vh - 200px);
            }

            .stack-trace::-webkit-scrollbar {
                width: 8px;
            }

            .stack-trace::-webkit-scrollbar-track {
                background: var(--bg-primary);
            }

            .stack-trace::-webkit-scrollbar-thumb {
                background: var(--bg-tertiary);
                border-radius: 4px;
            }

            .stack-trace::-webkit-scrollbar-thumb:hover {
                background: var(--text-muted);
            }

            .stack-header {
                padding: 1.5rem;
                border-bottom: 1px solid var(--border-color);
                background: var(--bg-primary);
            }

            .stack-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: var(--text-primary);
                margin-bottom: 0.5rem;
            }

            .stack-count {
                font-size: 0.875rem;
                color: var(--text-muted);
            }

            .stack-item {
                padding: 1rem 1.5rem;
                border-bottom: 1px solid var(--border-color);
                cursor: pointer;
                transition: all 0.2s ease;
                position: relative;
            }

            .stack-item:hover {
                background: var(--bg-tertiary);
            }

            .stack-item.active {
                background: var(--accent-secondary);
                border-left: 4px solid #3b82f6;
                color: white;
            }

            .stack-item.active::before {
                content: '';
                position: absolute;
                right: 1rem;
                top: 50%;
                transform: translateY(-50%);
                width: 8px;
                height: 8px;
                background: #60a5fa;
                border-radius: 50%;
            }

            .stack-header-content {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 0.5rem;
            }

            .stack-file {
                font-weight: 500;
                color: inherit;
                font-size: 0.9rem;
            }

            .stack-line {
                font-size: 0.8rem;
                color: var(--text-secondary);
                background: var(--bg-tertiary);
                padding: 0.2rem 0.5rem;
                border-radius: 4px;
            }

            .stack-item.active .stack-line {
                background: rgba(255, 255, 255, 0.2);
                color: rgba(255, 255, 255, 0.8);
            }

            .stack-function {
                font-size: 0.8rem;
                color: var(--text-secondary);
                font-family: 'Monaco', 'Menlo', monospace;
                margin: 0.25rem 0;
                font-style: italic;
            }

            .stack-item.active .stack-function {
                color: rgba(255, 255, 255, 0.7);
            }

            .stack-path {
                font-size: 0.8rem;
                color: var(--text-muted);
                font-family: 'Monaco', 'Menlo', monospace;
                word-break: break-all;
            }

            .stack-item.active .stack-path {
                color: rgba(255, 255, 255, 0.6);
            }

            .code-panel {
                flex: 1;
                background: var(--bg-primary);
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }

            .code-snippet {
                display: none;
                height: 100%;
                flex: 1;
                flex-direction: column;
            }

            .code-snippet.active {
                display: flex;
            }

            .code-header {
                background: var(--bg-secondary);
                padding: 1rem 1.5rem;
                border-bottom: 1px solid var(--border-color);
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
            }

            .code-header-info {
                flex: 1;
            }

            .code-file {
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.9rem;
                color: var(--text-primary);
                display: block;
                margin-bottom: 0.5rem;
            }

            .function-info {
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.8rem;
                color: var(--accent-secondary);
                margin-bottom: 0.25rem;
                font-weight: 500;
            }

            .args-info {
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.75rem;
                color: var(--text-secondary);
                background: var(--bg-tertiary);
                padding: 0.25rem 0.5rem;
                border-radius: 4px;
                border-left: 3px solid var(--accent-secondary);
                max-width: 100%;
                word-break: break-all;
            }

            .ide-button {
                background: var(--accent-secondary);
                color: white;
                border: none;
                padding: 0.4rem 0.8rem;
                border-radius: 4px;
                font-size: 0.75rem;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s ease;
                text-decoration: none;
                display: inline-block;
            }

            .ide-button:hover {
                background: #2563eb;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .code-content {
                overflow-y: auto;
                flex: 1;
                background: var(--bg-primary);
            }

            .code-content::-webkit-scrollbar {
                width: 8px;
            }

            .code-content::-webkit-scrollbar-track {
                background: var(--bg-secondary);
            }

            .code-content::-webkit-scrollbar-thumb {
                background: var(--bg-tertiary);
                border-radius: 4px;
            }

            .code-content::-webkit-scrollbar-thumb:hover {
                background: var(--text-muted);
            }

            .code-line {
                display: flex;
                align-items: flex-start;
                min-height: 1.5rem;
                transition: background 0.2s ease;
            }

            .code-line:hover {
                background: rgba(255, 255, 255, 0.02);
            }

            .code-line.error-line {
                background: var(--error-bg);
                border-left: 4px solid var(--error-border);
            }

            .line-number {
                width: 80px;
                min-width: 80px;
                padding: 0.25rem 1rem;
                text-align: right;
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.8rem;
                color: var(--text-muted);
                background: var(--bg-secondary);
                border-right: 1px solid var(--border-color);
                user-select: none;
                flex-shrink: 0;
            }

            .error-line .line-number {
                background: rgba(239, 68, 68, 0.2);
                color: #fca5a5;
            }

            .line-content {
                flex: 1;
                padding: 0.25rem 1rem;
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.85rem;
                white-space: pre;
                color: var(--text-primary);
            }

            /* Syntax highlighting */
            .keyword {
                color: #c792ea;
                font-weight: 600;
            }

            .string {
                color: #c3e88d;
            }

            .comment {
                color: #546e7a;
                font-style: italic;
            }

            .variable {
                color: #82aaff;
            }

            .number {
                color: #f78c6c;
            }

            .php-tag {
                color: #ff5370;
                font-weight: bold;
            }

            .class-name {
                color: #ffcb6b;
                font-weight: 600;
            }

            .function-call {
                color: #89ddff;
            }

            .superglobal {
                color: #ff9cac;
                font-weight: 600;
            }

            .operator {
                color: #89ddff;
            }

            /* Light theme */
            [data-theme="light"] .keyword {
                color: #9c27b0;
                font-weight: 600;
            }

            [data-theme="light"] .string {
                color: #4caf50;
            }

            [data-theme="light"] .comment {
                color: #757575;
            }

            [data-theme="light"] .variable {
                color: #2196f3;
            }

            [data-theme="light"] .number {
                color: #ff5722;
            }

            [data-theme="light"] .php-tag {
                color: #e91e63;
                font-weight: bold;
            }

            [data-theme="light"] .class-name {
                color: #ff9800;
                font-weight: 600;
            }

            [data-theme="light"] .function-call {
                color: #00bcd4;
            }

            [data-theme="light"] .superglobal {
                color: #e91e63;
                font-weight: 600;
            }

            [data-theme="light"] .operator {
                color: #607d8b;
            }

            .no-file {
                padding: 2rem;
                text-align: center;
                color: var(--text-muted);
                font-style: italic;
            }

            .suggestion-panel {
                margin-bottom: 10px;
                background: rgba(255, 255, 255, 0.1);
                backdrop-filter: blur(10px);
                border-radius: 12px;
                padding: 1rem 1.5rem;
                border: 1px solid rgba(255, 255, 255, 0.2);
                align-items: center;
                gap: 0.75rem;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .suggestion-content {
                max-width: 1200px;
                margin: 0 auto;
            }

            .suggestion-title {
                color: var(--text-primary);
                margin-bottom: 1rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 1.1rem;
                font-weight: 600;
            }

            .suggestion-box {
                font-size: 14pt;
                color: rgba(255, 255, 255, 0.7);
            }

            .debug-info {
                background: var(--bg-secondary);
                border-top: 1px solid var(--border-color);
                padding: 2rem;
            }

            .debug-section {
                margin-bottom: 2rem;
            }

            .debug-title {
                font-size: 1.1rem;
                font-weight: 600;
                color: var(--text-primary);
                margin-bottom: 1rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .debug-content {
                background: var(--bg-primary);
                border-radius: 8px;
                padding: 1rem;
                border: 1px solid var(--border-color);
                max-height: 300px;
                overflow-y: auto;
            }

            .debug-content::-webkit-scrollbar {
                width: 8px;
            }

            .debug-content::-webkit-scrollbar-track {
                background: var(--bg-secondary);
            }

            .debug-content::-webkit-scrollbar-thumb {
                background: var(--bg-tertiary);
                border-radius: 4px;
            }

            .debug-content pre {
                font-family: 'Monaco', 'Menlo', monospace;
                font-size: 0.8rem;
                color: var(--text-secondary);
                white-space: pre-wrap;
                word-break: break-word;
            }

            .framework-info {
                position: absolute;
                top: 1rem;
                right: 2rem;
                display: flex;
                gap: 1rem;
                font-size: 0.8rem;
                color: rgba(255, 255, 255, 0.7);
            }

            .framework-badge {
                background: rgba(255, 255, 255, 0.1);
                padding: 0.25rem 0.75rem;
                border-radius: 20px;
                backdrop-filter: blur(10px);
            }

            .controls {
                position: fixed;
                bottom: 2rem;
                right: 2rem;
                display: flex;
                gap: 1rem;
                align-items: center;
            }

            .theme-toggle {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 0.5rem;
                cursor: pointer;
                color: var(--text-primary);
                transition: all 0.2s ease;
            }

            .theme-toggle:hover {
                background: var(--bg-tertiary);
            }

            .ide-selector {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 0.5rem;
            }

            .ide-selector select {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                color: var(--text-primary);
                font-size: 0.8rem;
                cursor: pointer;
                border-radius: 4px;
                padding: 0.25rem;
            }

            .ide-selector select option {
                background: var(--bg-secondary);
                color: var(--text-primary);
            }

            @media (max-width: 768px) {
                .main-content {
                    flex-direction: column;
                }

                .stack-trace {
                    width: 100%;
                    max-height: 300px;
                }

                .error-title {
                    font-size: 2rem;
                }

                .framework-info {
                    position: static;
                    margin-top: 1rem;
                    justify-content: center;
                }

                .controls {
                    position: static;
                    justify-content: center;
                    margin-top: 2rem;
                }
            }
        </style>
    </head>

    <body data-theme="dark">
        <div class="error-container">
            <div class="error-header">
                <div class="framework-info">
                    <div class="framework-badge">PHP <?= phpversion() ?></div>
                    <div class="framework-badge">zFramework <?= FRAMEWORK_VERSION ?></div>
                </div>

                <div class="error-content">
                    <h1 class="error-title">
                        <span><?= $errorDetails['icon'] ?></span>
                        Whoops! Bir şeyler ters gitti.
                    </h1>
                    <p class="error-subtitle"><?= $message ?></p>

                    <?php if ($err_code) : ?>
                        <?php $suggestion = dirname(__FILE__) . "/suggestions/$err_code.php" ?>
                        <?php if (is_file($suggestion)) : ?>
                            <div class="error-details">
                                <?php include($suggestion) ?>
                            </div>
                        <?php endif ?>
                    <?php endif ?>

                    <?php if (!$err_code || !is_file($suggestion)): ?>
                        <div class="error-details">
                            <div class="error-type"><?= $errorDetails['type'] ?></div>
                            <div class="error-description"><?= $errorDetails['description'] ?></div>
                        </div>
                    <?php endif ?>

                    <div class="error-location" onclick="goIDE('<?= str_replace("\\", "/", $data[3]) ?>', <?= $data[4] ?>)">
                        <div class="error-location-icon">📍</div>
                        <div class="error-location-text">
                            <?= basename($data[3]) ?>:<?= $data[4] ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="main-content">
                <div class="stack-trace">
                    <div class="stack-header">
                        <div class="stack-title">Stack Trace</div>
                        <div class="stack-count"><?= count($stackTrace) ?> çerçeve</div>
                    </div>
                    <?= getStackTrace($stackTrace) ?>
                </div>

                <div class="code-panel">
                    <?= getCodeSnippets($stackTrace) ?>
                </div>
            </div>
        </div>

        <div class="debug-info">
            <div style="max-width: 1200px; margin: 0 auto;">
                <div class="debug-section">
                    <div class="debug-title">🔍 Request Bilgileri</div>
                    <div class="debug-content">
                        <pre><?= print_r($_REQUEST, true) ?></pre>
                    </div>
                </div>

                <div class="debug-section">
                    <div class="debug-title">👤 Kullanıcı Bilgileri</div>
                    <div class="debug-content">
                        <pre><?php
                                try {
                                    print_r(Auth::check() ? Auth::user() : ['message' => 'Kullanıcı oturum açmamış']);
                                } catch (\Throwable $user_exception) {
                                    echo 'CANNOT ACCESS USER INFORMATIONS';
                                } ?></pre>
                    </div>
                </div>

                <div class="debug-section">
                    <div class="debug-title">🌐 Server Bilgileri</div>
                    <div class="debug-content">
                        <pre><?= print_r(array_filter($_SERVER, fn($key) => !in_array($key, ['HTTP_COOKIE', 'HTTP_AUTHORIZATION']), ARRAY_FILTER_USE_KEY), true) ?></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="controls">
            <button class="theme-toggle" onclick="toggleTheme()">
                <span id="theme-icon">🌙</span>
            </button>

            <div class="ide-selector">
                <select name="IDE" onchange="document.cookie = 'IDE=' + this.value + '; expires=Sun, 1 Jan <?= date('Y') + 1 ?> 00:00:00 UTC; path=/'">
                    <?php foreach (['vscode' => 'VS Code', 'phpstorm' => 'PHPStorm'] as $val => $title) : ?>
                        <option value="<?= $val ?>" <?= @$_COOKIE['IDE'] == $val ? ' selected' : null ?>><?= $title ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <script>
            // Theme toggle
            function toggleTheme() {
                const body = document.body;
                const themeIcon = document.getElementById('theme-icon');
                const currentTheme = body.getAttribute('data-theme');

                if (currentTheme === 'dark') {
                    body.setAttribute('data-theme', 'light');
                    themeIcon.textContent = '☀️';
                    localStorage.setItem('theme', 'light');
                } else {
                    body.setAttribute('data-theme', 'dark');
                    themeIcon.textContent = '🌙';
                    localStorage.setItem('theme', 'dark');
                }
            }

            // Load saved theme
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.body.setAttribute('data-theme', savedTheme);
            document.getElementById('theme-icon').textContent = savedTheme === 'dark' ? '🌙' : '☀️';

            // Stack trace navigation
            function initStackTrace() {
                const stackItems = document.querySelectorAll('.stack-item');
                const codeSnippets = document.querySelectorAll('.code-snippet');

                console.log('Stack items found:', stackItems.length);
                console.log('Code snippets found:', codeSnippets.length);

                // Her stack item için click event ekle
                for (let i = 0; i < stackItems.length; i++) {
                    stackItems[i].onclick = function() {
                        console.log('Clicked stack item index:', i);

                        // Tüm active class'ları kaldır
                        for (let j = 0; j < stackItems.length; j++) {
                            stackItems[j].classList.remove('active');
                        }
                        for (let j = 0; j < codeSnippets.length; j++) {
                            codeSnippets[j].classList.remove('active');
                        }

                        // Tıklanan item'ı aktif yap
                        stackItems[i].classList.add('active');

                        // İlgili code snippet'i aktif yap
                        if (codeSnippets[i]) {
                            codeSnippets[i].classList.add('active');
                            console.log('Activated code snippet:', i);

                            // Error line'ı görünür alana kaydır
                            const errorLine = codeSnippets[i].querySelector('.error-line');
                            // if (errorLine) {
                            //     setTimeout(function() {
                            //         errorLine.scrollIntoView({
                            //             behavior: 'smooth',
                            //             block: 'center'
                            //         });
                            //     }, 100);
                            // }
                        }
                    };
                }

                // Ana hata kaynağını bul ve aktif yap
                if (stackItems.length > 0 && codeSnippets.length > 0) {
                    let activeIndex = 0; // Varsayılan olarak ilk item

                    // Ana hata kaynağını bul (framework dosyaları dışındaki ilk hata)
                    for (let i = 0; i < stackItems.length; i++) {
                        const stackPath = stackItems[i].querySelector('.stack-path');
                        if (stackPath) {
                            const filePath = stackPath.textContent.toLowerCase();
                            // Framework dosyaları dışındaki ilk dosyayı bul
                            if (!filePath.includes('zframework') &&
                                !filePath.includes('vendor') &&
                                !filePath.includes('system') &&
                                !filePath.includes('core')) {
                                activeIndex = i;
                                break;
                            }
                        }
                    }

                    stackItems[activeIndex].classList.add('active');
                    codeSnippets[activeIndex].classList.add('active');

                    // Error line'ı görünür alana kaydır
                    const errorLine = codeSnippets[activeIndex].querySelector('.error-line');
                    // if (errorLine) {
                    //     setTimeout(function() {
                    //         errorLine.scrollIntoView({
                    //             behavior: 'smooth',
                    //             block: 'center'
                    //         });
                    //     }, 500);
                    // }
                }

                // Syntax highlighting JavaScript tarafında kaldırıldı
                // PHP tarafında htmlspecialchars zaten kullanılıyor (99. satır)
                // highlightCode();
            }

            // Sayfa yüklendiğinde çalıştır
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initStackTrace);
            } else {
                initStackTrace();
            }

            // JavaScript Syntax Highlighting
            function highlightCode() {
                const codeLines = document.querySelectorAll('.line-content');

                codeLines.forEach(function(line) {
                    // Eğer zaten highlight edilmişse tekrar yapma
                    if (line.querySelector('.keyword, .string, .comment, .variable, .number')) return;

                    let code = line.textContent;

                    // HTML attribute'ları ve tag'ları içeren satırları kontrol et
                    // Escape edilmiş HTML karakterlerini de kontrol et
                    if (code.includes('&lt;') || code.includes('&gt;') || code.includes('&quot;') ||
                        code.includes('class=') || code.includes('id=') || code.includes('style=') ||
                        /<[a-zA-Z]+[^>]*>/.test(code) || /<\/[a-zA-Z]+>/.test(code)) {
                        return; // HTML içeren satırlar için highlight yapma
                    }

                    // Normal PHP kodu için tam highlight

                    // 1. Comments
                    code = code.replace(/(\/\/.*$)/gm, '<span class="comment">$1</span>');
                    code = code.replace(/(#.*$)/gm, '<span class="comment">$1</span>');
                    code = code.replace(/(\/\*.*?\*\/)/g, '<span class="comment">$1</span>');

                    // 2. Strings
                    code = code.replace(/('([^'\\]|\\.)*')/g, '<span class="string">$1</span>');
                    code = code.replace(/("([^"\\]|\\.)*")/g, '<span class="string">$1</span>');

                    // 3. PHP Keywords
                    const keywords = ['function', 'class', 'public', 'private', 'protected', 'static', 'return', 'if', 'else', 'elseif', 'foreach', 'for', 'while', 'switch', 'case', 'break', 'continue', 'try', 'catch', 'throw', 'new', 'extends', 'implements', 'interface', 'abstract', 'final', 'const', 'var', 'echo', 'print', 'include', 'require', 'namespace', 'use', 'as', 'true', 'false', 'null'];

                    keywords.forEach(function(keyword) {
                        const regex = new RegExp('\\b' + keyword + '\\b', 'g');
                        code = code.replace(regex, '<span class="keyword">' + keyword + '</span>');
                    });

                    // 4. Variables
                    code = code.replace(/(\$[a-zA-Z_][a-zA-Z0-9_]*)/g, '<span class="variable">$1</span>');

                    // 5. Numbers
                    code = code.replace(/\b(\d+\.?\d*)\b/g, '<span class="number">$1</span>');

                    line.innerHTML = code;
                });
            }

            // IDE integration
            function goIDE(file, line, caret = 0) {
                const ide = document.querySelector('[name="IDE"]').value;
                let link = '#';

                switch (ide) {
                    case 'vscode':
                        link = `vscode://file/${file}:${line}:${caret}`;
                        break;
                    case 'phpstorm':
                        link = `phpstorm://open?url=${file}&line=${line}`;
                        break;
                }

                try {
                    window.location.href = link;
                } catch (e) {
                    console.log('IDE link failed:', e);
                    alert('IDE bağlantısı açılamadı. IDE\'nizin çalıştığından emin olun.');
                }
            }
        </script>
    </body>

    </html>

<?php
    $error_log = ob_get_clean();
    if (Config::get('app.error.logging')) {
        file_put_contents2(ERROR_LOG_DIR . '/' . date('Y-m-d-H-i-s') . '.html', $error_log);
        Config::get('app.error.callback')($error_log);
    }

    if (!Config::get('app.debug')) {
        if (Http::isAjax()) abort(500, $message);
        abort(500, 'Beklenmedik bir hata oluştu, devam ederse lütfen yönetici ile iletişime geçiniz.');
    }

    echo $error_log;
    return $error_log;
}

set_exception_handler('errorHandler');
