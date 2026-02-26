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

        // Dosya yoluna göre vendor/app ayrımı
        $isVendor = (
            stripos($trace['file'], 'vendor') !== false ||
            stripos($trace['file'], 'zframework') !== false ||
            stripos($trace['file'], 'system') !== false ||
            stripos($trace['file'], 'core') !== false
        );
        $tagClass = $isVendor ? 'tag-vendor' : 'tag-app';
        $tagLabel = $isVendor ? 'VENDOR' : 'APP';

        $output .= '<div class="stack-item" data-index="' . $trace['index'] . '">';
        $output .= '<div class="stack-item-inner">';
        $output .= '<div class="stack-top-row">';
        $output .= '<div class="stack-file-info">';
        $output .= '<span class="stack-tag ' . $tagClass . '">' . $tagLabel . '</span>';
        $output .= '<span class="stack-filename">' . basename($trace['file']) . '</span>';
        $output .= '<span class="stack-line-badge">:' . $trace['line'] . '</span>';
        $output .= '</div>';
        $output .= '<svg class="stack-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>';
        $output .= '</div>';
        if ($functionName) {
            $output .= '<div class="stack-function">' . htmlspecialchars($functionName) . '</div>';
        }
        $output .= '<div class="stack-path">' . $trace['file'] . '</div>';
        $output .= '</div>';
        $output .= '</div>';
    }

    return $output;
}

function getCodeSnippets($stackTrace)
{
    $output = '';

    foreach ($stackTrace as $trace) {
        $output .= '<div class="code-snippet" data-index="' . $trace['index'] . '">';

        if (file_exists($trace['file'])) {
            $lines = file($trace['file']);
            $errorLine = $trace['line'];
            $startLine = max(1, $errorLine - 15);
            $endLine = min(count($lines), $errorLine + 15);

            $output .= '<div class="code-header">';
            $output .= '<div class="code-header-left">';
            $output .= '<div class="code-filepath">';
            $output .= '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>';
            $output .= '<span>' . $trace['file'] . '</span>';
            $output .= '<span class="code-line-num">:' . $errorLine . '</span>';
            $output .= '</div>';

            if (!empty($trace['function'])) {
                $functionInfo = '';
                if (!empty($trace['class'])) {
                    $functionInfo = $trace['class'] . $trace['type'] . $trace['function'] . '()';
                } else {
                    $functionInfo = $trace['function'] . '()';
                }
                $output .= '<div class="code-function-badge">';
                $output .= '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
                $output .= '<span>' . htmlspecialchars($functionInfo) . '</span>';
                $output .= '</div>';
            }

            if (!empty($trace['args'])) {
                $output .= '<div class="code-args">';
                $output .= '<span class="code-args-label">Args</span>';
                $argStrings = [];
                foreach ($trace['args'] as $arg) {
                    if (is_string($arg)) {
                        $argStrings[] = '"' . htmlspecialchars(mb_strimwidth($arg, 0, 80, '...')) . '"';
                    } elseif (is_numeric($arg)) {
                        $argStrings[] = $arg;
                    } elseif (is_bool($arg)) {
                        $argStrings[] = $arg ? 'true' : 'false';
                    } elseif (is_null($arg)) {
                        $argStrings[] = 'null';
                    } elseif (is_array($arg)) {
                        $argStrings[] = 'Array(' . count($arg) . ')';
                    } elseif (is_object($arg)) {
                        $argStrings[] = get_class($arg);
                    } else {
                        $argStrings[] = gettype($arg);
                    }
                }
                $output .= '<span class="code-args-values">' . implode(', ', $argStrings) . '</span>';
                $output .= '</div>';
            }

            $output .= '</div>';
            $output .= '<button class="ide-btn" onclick="goIDE(\'' . str_replace("\\", "/", $trace['file']) . '\', ' . $errorLine . ')">';
            $output .= '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';
            $output .= '<span>IDE\'de Aç</span>';
            $output .= '</button>';
            $output .= '</div>';

            $output .= '<div class="code-content">';
            for ($i = $startLine; $i <= $endLine; $i++) {
                $lineContent = isset($lines[$i - 1]) ? rtrim($lines[$i - 1]) : '';
                $isErrorLine = $i === $errorLine;
                $lineClass = $isErrorLine ? 'error-line' : '';

                $output .= '<div class="code-line ' . $lineClass . '">';
                $output .= '<span class="line-number">' . $i . '</span>';
                $output .= '<span class="line-content">' . htmlspecialchars($lineContent) . '</span>';
                if ($isErrorLine) {
                    $output .= '<span class="error-marker"></span>';
                }
                $output .= '</div>';
            }
            $output .= '</div>';
        } else {
            $output .= '<div class="code-header">';
            $output .= '<div class="code-header-left">';
            $output .= '<span class="code-filepath">' . $trace['file'] . ':' . $trace['line'] . '</span>';
            $output .= '</div>';
            $output .= '</div>';
            $output .= '<div class="code-content">';
            $output .= '<div class="no-file">';
            $output .= '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.3"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><line x1="9" y1="15" x2="15" y2="9"/></svg>';
            $output .= '<span>Dosya bulunamadı</span>';
            $output .= '</div>';
            $output .= '</div>';
        }

        $output .= '</div>';
    }

    return $output;
}

function getErrorDetails($message, $file, $line)
{
    $details = [];

    if (strpos($message, 'SQLSTATE') !== false) {
        $details['type'] = 'Database Error';
        $details['icon'] = 'database';
        $details['color'] = '#f59e0b';
        $details['description'] = 'Veritabanı sorgusu sırasında bir hata oluştu. SQL syntax\'ınızı kontrol edin.';
    } elseif (strpos($message, 'Fatal error') !== false) {
        $details['type'] = 'Fatal Error';
        $details['icon'] = 'fatal';
        $details['color'] = '#ef4444';
        $details['description'] = 'Kritik bir hata oluştu ve script çalışması durdu.';
    } elseif (strpos($message, 'Parse error') !== false) {
        $details['type'] = 'Parse Error';
        $details['icon'] = 'parse';
        $details['color'] = '#a855f7';
        $details['description'] = 'PHP kodu parse edilemedi. Syntax hatası var.';
    } elseif (strpos($message, 'Warning') !== false) {
        $details['type'] = 'Warning';
        $details['icon'] = 'warning';
        $details['color'] = '#f59e0b';
        $details['description'] = 'Bir uyarı oluştu ama script çalışmaya devam etti.';
    } elseif (strpos($message, 'Undefined') !== false || strpos($message, 'undefined') !== false) {
        $details['type'] = 'Reference Error';
        $details['icon'] = 'reference';
        $details['color'] = '#f97316';
        $details['description'] = 'Tanımsız bir değişken, metod veya sınıfa erişim denendi.';
    } elseif (strpos($message, 'TypeError') !== false || strpos($message, 'type') !== false) {
        $details['type'] = 'Type Error';
        $details['icon'] = 'type';
        $details['color'] = '#ec4899';
        $details['description'] = 'Tür uyumsuzluğu hatası oluştu.';
    } else {
        $details['type'] = 'Error';
        $details['icon'] = 'error';
        $details['color'] = '#ef4444';
        $details['description'] = 'Bir hata oluştu.';
    }

    return $details;
}

function getErrorIcon($type)
{
    $icons = [
        'database' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
        'fatal' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        'parse' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'warning' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'reference' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'type' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
        'error' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    ];
    return $icons[$type] ?? $icons['error'];
}


function errorHandler($data)
{
    @ob_end_clean();

    ob_start();
    $data = array_values((array) $data);
    $message = $data[0];

    $err_code = $data[2];
    $errorDetails = getErrorDetails($message, $data[3], $data[4]);

    $stackTrace = [];
    $seenTraces = [];

    $mainKey = $data[3] . ':' . $data[4];
    if (!isset($seenTraces[$mainKey])) {
        $mainArgs = [];
        $mainFunction = '';
        $mainClass = '';
        $mainType = '';

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
        <title><?= $errorDetails['type'] ?> — zFramework</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            /* ═══════════════════════════════════════════
               DESIGN SYSTEM — zFramework Error Handler
               ═══════════════════════════════════════════ */
            :root {
                /* Dark theme (default) */
                --surface-0: #09090b;
                --surface-1: #0c0c0f;
                --surface-2: #131318;
                --surface-3: #1a1a21;
                --surface-4: #222230;
                --surface-hover: #1e1e28;

                --text-100: #fafafa;
                --text-200: #d4d4d8;
                --text-300: #a1a1aa;
                --text-400: #71717a;
                --text-500: #52525b;

                --red-500: #ef4444;
                --red-400: #f87171;
                --red-900: rgba(239, 68, 68, 0.08);
                --red-border: rgba(239, 68, 68, 0.2);

                --blue-500: #3b82f6;
                --blue-400: #60a5fa;
                --blue-900: rgba(59, 130, 246, 0.08);
                --blue-border: rgba(59, 130, 246, 0.25);

                --green-500: #22c55e;
                --green-900: rgba(34, 197, 94, 0.08);
                --green-border: rgba(34, 197, 94, 0.2);

                --amber-500: #f59e0b;
                --amber-900: rgba(245, 158, 11, 0.08);

                --purple-500: #a855f7;

                --border: rgba(255, 255, 255, 0.06);
                --border-strong: rgba(255, 255, 255, 0.1);

                --radius-sm: 6px;
                --radius-md: 10px;
                --radius-lg: 14px;
                --radius-xl: 20px;

                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
                --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
                --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.5);

                --header-gradient: linear-gradient(135deg, #18181b 0%, #1c1025 50%, #1a0a0a 100%);

                --code-bg: #0d0d10;
                --code-gutter: #111114;
                --code-error-bg: rgba(239, 68, 68, 0.06);
                --code-error-gutter: rgba(239, 68, 68, 0.15);
                --code-error-border: #ef4444;
            }

            [data-theme="light"] {
                --surface-0: #ffffff;
                --surface-1: #fafafa;
                --surface-2: #f4f4f5;
                --surface-3: #e4e4e7;
                --surface-4: #d4d4d8;
                --surface-hover: #f0f0f3;

                --text-100: #09090b;
                --text-200: #27272a;
                --text-300: #52525b;
                --text-400: #71717a;
                --text-500: #a1a1aa;

                --red-500: #dc2626;
                --red-400: #ef4444;
                --red-900: rgba(220, 38, 38, 0.04);
                --red-border: rgba(220, 38, 38, 0.15);

                --blue-500: #2563eb;
                --blue-400: #3b82f6;
                --blue-900: rgba(37, 99, 235, 0.04);
                --blue-border: rgba(37, 99, 235, 0.2);

                --green-500: #16a34a;
                --green-900: rgba(22, 163, 74, 0.04);
                --green-border: rgba(22, 163, 74, 0.15);

                --amber-500: #d97706;
                --amber-900: rgba(217, 119, 6, 0.04);

                --purple-500: #9333ea;

                --border: rgba(0, 0, 0, 0.06);
                --border-strong: rgba(0, 0, 0, 0.1);

                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
                --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.06);
                --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.08);

                --header-gradient: linear-gradient(135deg, #fafafa 0%, #f5f0ff 50%, #fff5f5 100%);

                --code-bg: #fafafa;
                --code-gutter: #f4f4f5;
                --code-error-bg: rgba(220, 38, 38, 0.04);
                --code-error-gutter: rgba(220, 38, 38, 0.08);
                --code-error-border: #dc2626;
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
                background: var(--surface-0);
                color: var(--text-100);
                line-height: 1.6;
                overflow-x: hidden;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }

            /* ─── Scrollbar ─── */
            ::-webkit-scrollbar {
                width: 6px;
                height: 6px;
            }

            ::-webkit-scrollbar-track {
                background: transparent;
            }

            ::-webkit-scrollbar-thumb {
                background: var(--surface-4);
                border-radius: 10px;
            }

            ::-webkit-scrollbar-thumb:hover {
                background: var(--text-500);
            }

            /* ─── Layout ─── */
            .error-page {
                min-height: 100vh;
                display: flex;
                flex-direction: column;
            }

            /* ─── Header ─── */
            .error-header {
                background: var(--header-gradient);
                border-bottom: 1px solid var(--border);
                padding: 0;
                position: relative;
                overflow: hidden;
            }

            .error-header::before {
                content: '';
                position: absolute;
                inset: 0;
                background:
                    radial-gradient(ellipse 600px 300px at 20% 50%, rgba(239, 68, 68, 0.07), transparent),
                    radial-gradient(ellipse 400px 200px at 80% 30%, rgba(59, 130, 246, 0.05), transparent);
                pointer-events: none;
            }

            .header-inner {
                max-width: 1440px;
                margin: 0 auto;
                padding: 1.75rem 2.5rem;
                position: relative;
                z-index: 1;
            }

            .header-top-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 1.25rem;
            }

            .brand {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .brand-logo {
                width: 32px;
                height: 32px;
                background: linear-gradient(135deg, var(--red-500) 0%, #b91c1c 100%);
                border-radius: var(--radius-sm);
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25);
            }

            .brand-logo svg {
                color: white;
            }

            .brand-name {
                font-weight: 700;
                font-size: 0.9rem;
                color: var(--text-100);
                letter-spacing: -0.01em;
            }

            .brand-version {
                font-size: 0.7rem;
                color: var(--text-500);
                background: var(--surface-3);
                padding: 2px 8px;
                border-radius: 20px;
                font-family: 'IBM Plex Mono', monospace;
            }

            .header-badges {
                display: flex;
                gap: 8px;
                align-items: center;
            }

            .badge {
                font-size: 0.7rem;
                font-family: 'IBM Plex Mono', monospace;
                padding: 4px 10px;
                border-radius: 20px;
                background: var(--surface-3);
                color: var(--text-400);
                border: 1px solid var(--border);
            }

            .error-type-banner {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 1rem;
            }

            .error-type-icon {
                width: 44px;
                height: 44px;
                border-radius: var(--radius-md);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .error-type-label {
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                margin-bottom: 2px;
            }

            .error-type-title {
                font-size: 1.5rem;
                font-weight: 700;
                color: var(--text-100);
                letter-spacing: -0.02em;
                line-height: 1.2;
            }

            .error-message-box {
                background: var(--red-900);
                border: 1px solid var(--red-border);
                border-radius: var(--radius-md);
                padding: 1rem 1.25rem;
                margin-bottom: 1rem;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.85rem;
                color: var(--red-400);
                line-height: 1.7;
                word-break: break-word;
                position: relative;
                overflow: hidden;
            }

            .error-message-box::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 3px;
                background: var(--red-500);
                border-radius: 3px 0 0 3px;
            }

            .header-meta {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .meta-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 5px 12px;
                background: var(--surface-3);
                border: 1px solid var(--border);
                border-radius: var(--radius-sm);
                font-size: 0.75rem;
                font-family: 'IBM Plex Mono', monospace;
                color: var(--text-300);
                cursor: pointer;
                transition: all 0.2s;
            }

            .meta-chip:hover {
                background: var(--surface-4);
                color: var(--text-200);
            }

            .meta-chip svg {
                opacity: 0.5;
                flex-shrink: 0;
            }

            /* ─── Suggestion Panel ─── */
            .suggestion-panel {
                background: var(--green-900);
                border: 1px solid var(--green-border);
                border-radius: var(--radius-md);
                padding: 1rem 1.25rem;
                margin-bottom: 1rem;
                position: relative;
                overflow: hidden;
            }

            .suggestion-panel::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 3px;
                background: var(--green-500);
                border-radius: 3px 0 0 3px;
            }

            .suggestion-panel .suggestion-label {
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--green-500);
                margin-bottom: 6px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .suggestion-panel .suggestion-content {
                font-size: 0.85rem;
                color: var(--text-200);
                line-height: 1.6;
            }

            .suggestion-panel .suggestion-content .ide-btn {
                margin-top: 8px;
            }

            .suggestion-panel .suggestion-content a.ide-btn,
            .suggestion-panel .suggestion-content .ide-button {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                background: var(--green-500);
                color: white;
                border: none;
                border-radius: var(--radius-sm);
                font-size: 0.75rem;
                font-weight: 600;
                font-family: 'IBM Plex Mono', monospace;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.2s;
            }

            .suggestion-panel .suggestion-content a.ide-btn:hover,
            .suggestion-panel .suggestion-content .ide-button:hover {
                filter: brightness(1.1);
                transform: translateY(-1px);
            }

            .suggestion-panel kbd {
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.8rem;
                padding: 2px 6px;
                background: var(--surface-3);
                border: 1px solid var(--border);
                border-radius: 4px;
                color: var(--text-200);
            }

            /* ─── Main Split Layout ─── */
            .main-split {
                flex: 1;
                display: flex;
                overflow: hidden;
            }

            /* ─── Stack Trace Panel ─── */
            .stack-panel {
                width: 380px;
                min-width: 380px;
                background: var(--surface-1);
                border-right: 1px solid var(--border);
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .stack-panel-header {
                padding: 14px 20px;
                border-bottom: 1px solid var(--border);
                display: flex;
                align-items: center;
                justify-content: space-between;
                flex-shrink: 0;
            }

            .stack-panel-title {
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--text-200);
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .stack-panel-title svg {
                color: var(--text-400);
            }

            .stack-count-badge {
                font-size: 0.65rem;
                font-family: 'IBM Plex Mono', monospace;
                padding: 2px 8px;
                border-radius: 20px;
                background: var(--surface-3);
                color: var(--text-400);
            }

            .stack-list {
                flex: 1;
                overflow-y: auto;
            }

            .stack-item {
                border-bottom: 1px solid var(--border);
                cursor: pointer;
                transition: all 0.15s ease;
                position: relative;
            }

            .stack-item-inner {
                padding: 12px 20px;
            }

            .stack-item:hover {
                background: var(--surface-hover);
            }

            .stack-item.active {
                background: var(--blue-900);
                border-left: 3px solid var(--blue-500);
            }

            .stack-item.active .stack-chevron {
                opacity: 1;
                color: var(--blue-400);
            }

            .stack-item.active .stack-filename {
                color: var(--blue-400);
            }

            .stack-top-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 4px;
            }

            .stack-file-info {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .stack-tag {
                font-size: 0.55rem;
                font-weight: 700;
                font-family: 'IBM Plex Mono', monospace;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                padding: 2px 6px;
                border-radius: 4px;
            }

            .tag-app {
                background: var(--blue-900);
                color: var(--blue-400);
                border: 1px solid var(--blue-border);
            }

            .tag-vendor {
                background: var(--surface-3);
                color: var(--text-400);
                border: 1px solid var(--border);
            }

            .stack-filename {
                font-weight: 600;
                font-size: 0.82rem;
                color: var(--text-100);
                transition: color 0.15s;
            }

            .stack-line-badge {
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.75rem;
                color: var(--text-400);
            }

            .stack-chevron {
                color: var(--text-500);
                opacity: 0.4;
                transition: all 0.15s;
                flex-shrink: 0;
            }

            .stack-function {
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.72rem;
                color: var(--text-400);
                margin-bottom: 2px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .stack-path {
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.65rem;
                color: var(--text-500);
                word-break: break-all;
                line-height: 1.4;
            }

            /* ─── Code Panel ─── */
            .code-panel {
                flex: 1;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                background: var(--code-bg);
            }

            .code-snippet {
                display: none;
                flex-direction: column;
                height: 100%;
            }

            .code-snippet.active {
                display: flex;
            }

            .code-header {
                background: var(--surface-2);
                padding: 12px 20px;
                border-bottom: 1px solid var(--border);
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 16px;
                flex-shrink: 0;
            }

            .code-header-left {
                flex: 1;
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }

            .code-filepath {
                display: flex;
                align-items: center;
                gap: 8px;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.78rem;
                color: var(--text-300);
            }

            .code-filepath svg {
                color: var(--text-500);
                flex-shrink: 0;
            }

            .code-line-num {
                color: var(--red-400);
                font-weight: 600;
            }

            .code-function-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.72rem;
                color: var(--blue-400);
                padding: 3px 8px;
                background: var(--blue-900);
                border-radius: var(--radius-sm);
                border: 1px solid var(--blue-border);
                width: fit-content;
            }

            .code-function-badge svg {
                color: var(--blue-400);
                flex-shrink: 0;
            }

            .code-args {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.7rem;
            }

            .code-args-label {
                font-weight: 600;
                color: var(--text-500);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                white-space: nowrap;
                padding-top: 1px;
            }

            .code-args-values {
                color: var(--text-400);
                word-break: break-all;
            }

            .ide-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                background: var(--blue-500);
                color: white;
                border: none;
                border-radius: var(--radius-sm);
                font-size: 0.72rem;
                font-weight: 600;
                font-family: 'DM Sans', sans-serif;
                cursor: pointer;
                transition: all 0.2s;
                white-space: nowrap;
                flex-shrink: 0;
            }

            .ide-btn:hover {
                filter: brightness(1.15);
                transform: translateY(-1px);
                box-shadow: var(--shadow-md);
            }

            .code-content {
                flex: 1;
                overflow: auto;
                background: var(--code-bg);
            }

            .code-line {
                display: flex;
                align-items: stretch;
                min-height: 24px;
                transition: background 0.1s;
                position: relative;
            }

            .code-line:hover {
                background: rgba(255, 255, 255, 0.015);
            }

            [data-theme="light"] .code-line:hover {
                background: rgba(0, 0, 0, 0.015);
            }

            .code-line.error-line {
                background: var(--code-error-bg);
            }

            .code-line.error-line::after {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 3px;
                background: var(--code-error-border);
            }

            .error-marker {
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                width: 8px;
                height: 8px;
                background: var(--red-500);
                border-radius: 50%;
                box-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
                animation: pulse-dot 2s ease-in-out infinite;
            }

            @keyframes pulse-dot {

                0%,
                100% {
                    opacity: 1;
                    transform: translateY(-50%) scale(1);
                }

                50% {
                    opacity: 0.6;
                    transform: translateY(-50%) scale(0.8);
                }
            }

            .line-number {
                width: 70px;
                min-width: 70px;
                padding: 2px 16px 2px 0;
                text-align: right;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.72rem;
                color: var(--text-500);
                background: var(--code-gutter);
                border-right: 1px solid var(--border);
                user-select: none;
                flex-shrink: 0;
            }

            .error-line .line-number {
                background: var(--code-error-gutter);
                color: var(--red-400);
                font-weight: 600;
            }

            .line-content {
                flex: 1;
                padding: 2px 16px;
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.78rem;
                white-space: pre;
                color: var(--text-200);
                tab-size: 4;
            }

            .no-file {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                padding: 4rem;
                color: var(--text-500);
                font-size: 0.85rem;
            }

            /* ─── Debug Info Panels ─── */
            .debug-panels {
                border-top: 1px solid var(--border);
                background: var(--surface-1);
            }

            .debug-inner {
                max-width: 1440px;
                margin: 0 auto;
                padding: 2rem 2.5rem;
            }

            .debug-tabs {
                display: flex;
                gap: 4px;
                margin-bottom: 1.25rem;
                background: var(--surface-2);
                border-radius: var(--radius-md);
                padding: 4px;
                width: fit-content;
            }

            .debug-tab {
                padding: 7px 16px;
                font-size: 0.78rem;
                font-weight: 500;
                color: var(--text-400);
                background: transparent;
                border: none;
                border-radius: var(--radius-sm);
                cursor: pointer;
                transition: all 0.2s;
                font-family: 'DM Sans', sans-serif;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .debug-tab:hover {
                color: var(--text-200);
                background: var(--surface-3);
            }

            .debug-tab.active {
                background: var(--surface-0);
                color: var(--text-100);
                box-shadow: var(--shadow-sm);
                font-weight: 600;
            }

            .debug-section {
                display: none;
            }

            .debug-section.active {
                display: block;
            }

            .debug-content-box {
                background: var(--surface-2);
                border-radius: var(--radius-md);
                border: 1px solid var(--border);
                overflow: hidden;
            }

            .debug-content-box pre {
                font-family: 'IBM Plex Mono', monospace;
                font-size: 0.78rem;
                color: var(--text-300);
                padding: 1.25rem;
                overflow: auto;
                max-height: 350px;
                white-space: pre-wrap;
                word-break: break-word;
                line-height: 1.7;
            }

            /* ─── Controls (Fixed) ─── */
            .controls-bar {
                position: fixed;
                bottom: 20px;
                right: 20px;
                display: flex;
                gap: 8px;
                z-index: 100;
            }

            .ctrl-btn {
                width: 38px;
                height: 38px;
                border-radius: var(--radius-md);
                background: var(--surface-2);
                border: 1px solid var(--border-strong);
                color: var(--text-300);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s;
                box-shadow: var(--shadow-md);
                backdrop-filter: blur(12px);
            }

            .ctrl-btn:hover {
                background: var(--surface-3);
                color: var(--text-100);
                transform: translateY(-2px);
                box-shadow: var(--shadow-lg);
            }

            .ide-select {
                height: 38px;
                padding: 0 12px;
                border-radius: var(--radius-md);
                background: var(--surface-2);
                border: 1px solid var(--border-strong);
                color: var(--text-300);
                font-size: 0.72rem;
                font-family: 'IBM Plex Mono', monospace;
                cursor: pointer;
                box-shadow: var(--shadow-md);
                backdrop-filter: blur(12px);
                appearance: none;
                -webkit-appearance: none;
                padding-right: 28px;
                background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%2371717a' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 10px center;
            }

            .ide-select:hover {
                background-color: var(--surface-3);
                color: var(--text-100);
            }

            .ide-select option {
                background: var(--surface-2);
                color: var(--text-200);
            }

            /* ─── Animations ─── */
            @keyframes fadeIn {
                from {
                    opacity: 0;
                    transform: translateY(8px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .error-header {
                animation: fadeIn 0.4s ease-out;
            }

            .main-split {
                animation: fadeIn 0.5s ease-out 0.1s both;
            }

            .debug-panels {
                animation: fadeIn 0.5s ease-out 0.2s both;
            }

            /* ─── Responsive ─── */
            @media (max-width: 900px) {
                .main-split {
                    flex-direction: column;
                }

                .stack-panel {
                    width: 100%;
                    min-width: auto;
                    max-height: 280px;
                    border-right: none;
                    border-bottom: 1px solid var(--border);
                }

                .header-inner {
                    padding: 1.25rem 1.25rem;
                }

                .error-type-title {
                    font-size: 1.2rem;
                }

                .controls-bar {
                    bottom: 12px;
                    right: 12px;
                }

                .header-badges {
                    display: none;
                }
            }

            /* ─── Syntax Highlighting ─── */
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

            [data-theme="light"] .keyword {
                color: #7c3aed;
                font-weight: 600;
            }

            [data-theme="light"] .string {
                color: #16a34a;
            }

            [data-theme="light"] .comment {
                color: #9ca3af;
            }

            [data-theme="light"] .variable {
                color: #2563eb;
            }

            [data-theme="light"] .number {
                color: #ea580c;
            }

            [data-theme="light"] .php-tag {
                color: #dc2626;
                font-weight: bold;
            }

            [data-theme="light"] .class-name {
                color: #d97706;
                font-weight: 600;
            }

            [data-theme="light"] .function-call {
                color: #0891b2;
            }
        </style>
    </head>

    <body data-theme="dark">
        <div class="error-page">

            <!-- ═══ HEADER ═══ -->
            <div class="error-header">
                <div class="header-inner">
                    <div class="header-top-bar">
                        <div class="brand">
                            <div class="brand-logo">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                            </div>
                            <span class="brand-name">zFramework</span>
                            <span class="brand-version"><?= defined('FRAMEWORK_VERSION') ? FRAMEWORK_VERSION : 'dev' ?></span>
                        </div>
                        <div class="header-badges">
                            <span class="badge">PHP <?= phpversion() ?></span>
                            <span class="badge"><?= date('H:i:s') ?></span>
                            <span class="badge"><?= php_sapi_name() ?></span>
                        </div>
                    </div>

                    <div class="error-type-banner">
                        <div class="error-type-icon" style="background: <?= $errorDetails['color'] ?>15; border: 1px solid <?= $errorDetails['color'] ?>30; color: <?= $errorDetails['color'] ?>;">
                            <?= getErrorIcon($errorDetails['icon']) ?>
                        </div>
                        <div>
                            <div class="error-type-label" style="color: <?= $errorDetails['color'] ?>;"><?= $errorDetails['type'] ?></div>
                            <div class="error-type-title"><?= $errorDetails['description'] ?></div>
                        </div>
                    </div>

                    <div class="error-message-box"><?= htmlspecialchars($message) ?></div>

                    <?php if ($err_code) : ?>
                        <?php $suggestion = dirname(__FILE__) . "/suggestions/$err_code.php" ?>
                        <?php if (is_file($suggestion)) : ?>
                            <div class="suggestion-panel">
                                <div class="suggestion-label">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M9.663 17h4.674M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                    Çözüm Önerisi
                                </div>
                                <div class="suggestion-content">
                                    <?php include($suggestion) ?>
                                </div>
                            </div>
                        <?php endif ?>
                    <?php endif ?>

                    <div class="header-meta">
                        <div class="meta-chip" onclick="goIDE('<?= str_replace("\\", "/", $data[3]) ?>', <?= $data[4] ?>)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            <?= basename($data[3]) ?>:<?= $data[4] ?>
                        </div>
                        <div class="meta-chip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </svg>
                            <?= $_SERVER['REQUEST_METHOD'] ?? 'CLI' ?> <?= $_SERVER['REQUEST_URI'] ?? '' ?>
                        </div>
                        <?php if (!empty($_SERVER['REMOTE_ADDR'])): ?>
                            <div class="meta-chip">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
                                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
                                </svg>
                                <?= $_SERVER['REMOTE_ADDR'] ?>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
            </div>

            <!-- ═══ MAIN SPLIT ═══ -->
            <div class="main-split">
                <div class="stack-panel">
                    <div class="stack-panel-header">
                        <div class="stack-panel-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="12 8 12 12 14 14" />
                                <path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5" />
                            </svg>
                            Stack Trace
                        </div>
                        <span class="stack-count-badge"><?= count($stackTrace) ?> frame</span>
                    </div>
                    <div class="stack-list">
                        <?= getStackTrace($stackTrace) ?>
                    </div>
                </div>

                <div class="code-panel">
                    <?= getCodeSnippets($stackTrace) ?>
                </div>
            </div>

            <!-- ═══ DEBUG PANELS ═══ -->
            <div class="debug-panels">
                <div class="debug-inner">
                    <div class="debug-tabs">
                        <button class="debug-tab active" data-target="debug-request">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                            </svg>
                            Request
                        </button>
                        <button class="debug-tab" data-target="debug-user">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            Kullanıcı
                        </button>
                        <button class="debug-tab" data-target="debug-server">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="2" width="20" height="8" rx="2" ry="2" />
                                <rect x="2" y="14" width="20" height="8" rx="2" ry="2" />
                                <line x1="6" y1="6" x2="6.01" y2="6" />
                                <line x1="6" y1="18" x2="6.01" y2="18" />
                            </svg>
                            Server
                        </button>
                    </div>

                    <div class="debug-section active" id="debug-request">
                        <div class="debug-content-box">
                            <pre><?= print_r($_REQUEST, true) ?></pre>
                        </div>
                    </div>

                    <div class="debug-section" id="debug-user">
                        <div class="debug-content-box">
                            <pre><?php
                                    try {
                                        print_r(Auth::check() ? Auth::user() : ['message' => 'Kullanıcı oturum açmamış']);
                                    } catch (\Throwable $user_exception) {
                                        echo 'CANNOT ACCESS USER INFORMATIONS';
                                    } ?></pre>
                        </div>
                    </div>

                    <div class="debug-section" id="debug-server">
                        <div class="debug-content-box">
                            <pre><?= print_r(array_filter($_SERVER, fn($key) => !in_array($key, ['HTTP_COOKIE', 'HTTP_AUTHORIZATION']), ARRAY_FILTER_USE_KEY), true) ?></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ CONTROLS ═══ -->
        <div class="controls-bar">
            <button class="ctrl-btn" onclick="toggleTheme()" title="Tema Değiştir" id="theme-btn">
                <svg id="theme-icon-dark" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
                </svg>
                <svg id="theme-icon-light" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                    <circle cx="12" cy="12" r="5" />
                    <line x1="12" y1="1" x2="12" y2="3" />
                    <line x1="12" y1="21" x2="12" y2="23" />
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
                    <line x1="1" y1="12" x2="3" y2="12" />
                    <line x1="21" y1="12" x2="23" y2="12" />
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
                </svg>
            </button>
            <select class="ide-select" name="IDE" onchange="document.cookie='IDE='+this.value+';expires=Sun, 1 Jan <?= date('Y') + 1 ?> 00:00:00 UTC;path=/'" title="IDE Seçimi">
                <?php foreach (['vscode' => 'VS Code', 'phpstorm' => 'PHPStorm', 'sublime' => 'Sublime'] as $val => $title) : ?>
                    <option value="<?= $val ?>" <?= @$_COOKIE['IDE'] == $val ? ' selected' : null ?>><?= $title ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <script>
            // ═══ Theme ═══
            function toggleTheme() {
                const body = document.body;
                const isDark = body.getAttribute('data-theme') === 'dark';
                body.setAttribute('data-theme', isDark ? 'light' : 'dark');
                document.getElementById('theme-icon-dark').style.display = isDark ? 'none' : 'block';
                document.getElementById('theme-icon-light').style.display = isDark ? 'block' : 'none';
                localStorage.setItem('zf-error-theme', isDark ? 'light' : 'dark');
            }

            (function() {
                const saved = localStorage.getItem('zf-error-theme');
                if (saved) {
                    document.body.setAttribute('data-theme', saved);
                    document.getElementById('theme-icon-dark').style.display = saved === 'dark' ? 'block' : 'none';
                    document.getElementById('theme-icon-light').style.display = saved === 'light' ? 'block' : 'none';
                }
            })();

            // ═══ Stack Trace Navigation ═══
            function initStackTrace() {
                const stackItems = document.querySelectorAll('.stack-item');
                const codeSnippets = document.querySelectorAll('.code-snippet');

                stackItems.forEach(function(item, i) {
                    item.addEventListener('click', function() {
                        stackItems.forEach(el => el.classList.remove('active'));
                        codeSnippets.forEach(el => el.classList.remove('active'));

                        item.classList.add('active');
                        if (codeSnippets[i]) {
                            codeSnippets[i].classList.add('active');

                            // Hata satırına scroll
                            const errorLine = codeSnippets[i].querySelector('.error-line');
                            if (errorLine) {
                                setTimeout(function() {
                                    errorLine.scrollIntoView({
                                        behavior: 'smooth',
                                        block: 'center'
                                    });
                                }, 150);
                            }
                        }
                    });
                });

                // İlk aktif frame'i belirle (framework dışı ilk dosya)
                if (stackItems.length > 0 && codeSnippets.length > 0) {
                    let activeIdx = 0;
                    for (let i = 0; i < stackItems.length; i++) {
                        const path = stackItems[i].querySelector('.stack-path');
                        if (path) {
                            const fp = path.textContent.toLowerCase();
                            if (!fp.includes('zframework') && !fp.includes('vendor') && !fp.includes('system') && !fp.includes('core')) {
                                activeIdx = i;
                                break;
                            }
                        }
                    }
                    stackItems[activeIdx].classList.add('active');
                    codeSnippets[activeIdx].classList.add('active');
                }
            }

            // ═══ Debug Tabs ═══
            function initDebugTabs() {
                const tabs = document.querySelectorAll('.debug-tab');
                const sections = document.querySelectorAll('.debug-section');

                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        tabs.forEach(t => t.classList.remove('active'));
                        sections.forEach(s => s.classList.remove('active'));

                        tab.classList.add('active');
                        const target = document.getElementById(tab.dataset.target);
                        if (target) target.classList.add('active');
                    });
                });
            }

            // ═══ Syntax Highlighting ═══
            function highlightCode() {
                document.querySelectorAll('.line-content').forEach(function(line) {
                    if (line.querySelector('.keyword, .string, .comment, .variable')) return;

                    let code = line.textContent;

                    // HTML satırlarını atla
                    if (/<[a-zA-Z]+[^>]*>/.test(code) || code.includes('&lt;') || code.includes('class=')) return;

                    code = code.replace(/(\/\/.*$)/gm, '<span class="comment">$1</span>');
                    code = code.replace(/(#.*$)/gm, '<span class="comment">$1</span>');
                    code = code.replace(/(\/\*.*?\*\/)/g, '<span class="comment">$1</span>');
                    code = code.replace(/('([^'\\]|\\.)*')/g, '<span class="string">$1</span>');
                    code = code.replace(/("([^"\\]|\\.)*")/g, '<span class="string">$1</span>');

                    ['function', 'class', 'public', 'private', 'protected', 'static', 'return', 'if', 'else', 'elseif',
                        'foreach', 'for', 'while', 'switch', 'case', 'break', 'continue', 'try', 'catch', 'throw', 'new',
                        'extends', 'implements', 'interface', 'abstract', 'final', 'const', 'var', 'echo', 'print',
                        'include', 'require', 'namespace', 'use', 'as', 'true', 'false', 'null', 'match', 'fn', 'array',
                        'isset', 'empty', 'unset', 'list'
                    ].forEach(function(kw) {
                        code = code.replace(new RegExp('\\b' + kw + '\\b', 'g'), '<span class="keyword">' + kw + '</span>');
                    });

                    code = code.replace(/(\$[a-zA-Z_][a-zA-Z0-9_]*)/g, '<span class="variable">$1</span>');
                    code = code.replace(/\b(\d+\.?\d*)\b/g, '<span class="number">$1</span>');

                    line.innerHTML = code;
                });
            }

            // ═══ IDE Integration ═══
            function goIDE(file, line, caret) {
                caret = caret || 0;
                const ide = document.querySelector('[name="IDE"]').value;
                let link = '#';

                switch (ide) {
                    case 'vscode':
                        link = 'vscode://file/' + file + ':' + line + ':' + caret;
                        break;
                    case 'phpstorm':
                        link = 'phpstorm://open?url=' + file + '&line=' + line;
                        break;
                    case 'sublime':
                        link = 'subl://open?url=file://' + file + '&line=' + line;
                        break;
                }

                try {
                    window.location.href = link;
                } catch (e) {
                    console.warn('IDE link failed:', e);
                }
            }

            // ═══ Keyboard Shortcuts ═══
            document.addEventListener('keydown', function(e) {
                // T = toggle theme
                if (e.key === 't' && !e.ctrlKey && !e.metaKey && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'SELECT') {
                    toggleTheme();
                }
                // Arrow keys for stack navigation
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                    const items = document.querySelectorAll('.stack-item');
                    const active = document.querySelector('.stack-item.active');
                    if (!active || items.length === 0) return;

                    let idx = Array.from(items).indexOf(active);
                    if (e.key === 'ArrowUp' && idx > 0) idx--;
                    if (e.key === 'ArrowDown' && idx < items.length - 1) idx++;

                    items[idx].click();
                    items[idx].scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                    e.preventDefault();
                }
            });

            // ═══ Init ═══
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    initStackTrace();
                    initDebugTabs();
                    // highlightCode();
                });
            } else {
                initStackTrace();
                initDebugTabs();
                // highlightCode();
            }
        </script>
    </body>

    </html>

<?php
    @$error_log = ob_get_clean();
    if (Config::get('app.error.logging')) {
        $error_log_file_name = ERROR_LOG_DIR . '/' . date('Y-m-d-H-i-s') . '.html';
        file_put_contents2($error_log_file_name, $error_log);
        Config::get('app.error.callback')($error_log_file_name, $error_log);
    }

    if (!Config::get('app.debug')) {
        if (Http::isAjax()) abort(500, $message);
        abort(500, 'Beklenmedik bir hata oluştu, devam ederse lütfen yönetici ile iletişime geçiniz.');
    }

    echo $error_log;
    return $error_log;
}

set_exception_handler('errorHandler');
