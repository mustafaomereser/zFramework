<?php

namespace zFramework\Core;

class View
{

    static $binds  = [];
    static $config = [];
    static $view;
    static $data;
    static $sections;
    static $directives = [];

    /**
     * Prepare config.
     */
    public static function setSettings(array $config = []): void
    {
        self::reset();
        self::$config = $config;
    }
    
    /**
     * reset all veriables.
     */
    private static function reset(): void
    {
        self::$view       = null;
        self::$data       = null;
        self::$sections   = [];
    }

    /**
     * Dispatch view
     * @param string $view_name
     * @param array $data
     * @return string
     */
    public static function view(string $view_name, array $data = [])
    {
        if (isset(self::$binds[$view_name])) $data = self::$binds[$view_name]() + $data;

        // search view path: start
        $view_path = self::$config['dir'] . '/' . self::parseViewName($view_name);
        // if doesn't exists in config->dir go search in modules.
        if (!is_file($view_path)) $view_path = base_path('modules/' . self::parseViewName($view_name));
        // if doesn't exists in config->dir go search in whole base path.
        if (!is_file($view_path)) $view_path = base_path(self::parseViewName($view_name));
        // search view path: end

        self::$view = file_get_contents($view_path);
        self::$data = $data;
        self::parse();


        if (self::$config['caching']) {
            # crate views folder.
            @mkdir(self::$config['caches'], 0777, true);
            $cache = self::$config['caches'] . '/' . $view_name . '.stored.php';
            file_put_contents($cache, self::$view);
        }

        ob_start();
        extract($data);

        if (self::$config['caching']) include($cache);
        else echo eval('?>' . self::$view);

        $output = ob_get_clean();
        self::reset();

        if (@self::$config['minify'] ?? false) {
            $parts = preg_split('/(<textarea.*?>.*?<\/textarea>|<pre.*?>.*?<\/pre>|<script.*?>.*?<\/script>|<input.*?>)/si', $output, -1, PREG_SPLIT_DELIM_CAPTURE);
            for ($i = 0; $i < count($parts); $i++) {
                if ($i % 2 == 0) {
                    $parts[$i] = preg_replace(['/\s+(?=(?:[^"\'`]*["\'`][^"\'`]*["\'`])*[^"\'`]*$)/', '/>\s+</'], [' ', '><'], $parts[$i]);
                } else if (strpos($parts[$i], '<script') !== false) {
                    $script = $parts[$i];
                    $script = preg_replace('/(?<!:)\/\/.*|\/\*(?!!)[\s\S]*?\*\//', '', $script);
                    $script = preg_replace('/\s+/', ' ', $script);
                    $script = preg_replace('/\s*([{}:;,])\s*/', '$1', $script);
                    $script = preg_replace('/\s*(\(|\)|\[|\])\s*/', '$1', $script);
                    $script = preg_replace('/([=+\-*\/<>])\s+/', '$1', $script);
                    $script = preg_replace('/\s+([=+\-*\/<>])/', '$1', $script);
                    $parts[$i] = trim($script);
                }
            }
            $output = implode('', $parts);
        }

        return $output;
    }

    /** 
     * Bind for extra parameters
     * @param string $view
     * @param object $callback
     * @return array;
     */
    public static function bind(string $view, $callback)
    {
        return self::$binds[$view] = $callback;
    }

    /**
     * parse 
     * @param string $name
     * @return string
     */
    private static function parseViewName(string $name): string
    {
        $name = str_replace('.', '/', $name);
        return $name . (!empty(self::$config['suffix']) ? '.' . self::$config['suffix'] : null) . '.php';
    }

    /**
     * parse view.
     */
    private static function parse(): void
    {
        self::parseIncludes();
        self::parsePHP();
        self::parseVariables();
        self::parseForEach();
        self::parseSections();
        self::parseExtends();
        self::parseYields();
        self::customDirectives();
        self::parseIfBlocks();
        self::parseEmpty();
        self::parseIsset();
        self::parseForElse();
        self::parseJSON();
        self::parseDump();
        self::parseDd();
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @php
     * @endphp
     */
    public static function parsePHP(): void
    {
        self::$view = preg_replace_callback('/@php(.*?)@endphp/s', function ($code) {
            return '<?php ' . $code[1] . ' ?>';
        }, self::$view);
    }

    /**
     * {{ $degisken }} yazılan her yeri <?=$degisken?> olarak değiştiren metod
     */
    public static function parseVariables(): void
    {
        self::$view = preg_replace_callback('/{{(.*?)}}/', function ($variable) {
            return '<?=' . trim($variable[1]) . '?>';
        }, self::$view);
    }

    /**
     * Aşağıdaki direktifler için parse işlemi yapar
     * @foreach($array as $item)
     * @endforeach
     */
    public static function parseForEach(): void
    {
        self::$view = preg_replace_callback('/@foreach\((.*?)\)/', function ($expression) {
            return '<?php foreach(' . $expression[1] . '): ?>';
        }, self::$view);

        self::$view = preg_replace('/@endforeach/', '<?php endforeach; ?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @include(view.adi)
     */
    public static function parseIncludes(): void
    {
        self::$view = preg_replace_callback('/@include\(\'(.*?)\'\)/', function ($viewName) {
            return file_get_contents(self::$config['views'] . '/' . self::parseViewName($viewName[1]));
        }, self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @extends(layout)
     */
    public static function parseExtends(): void
    {
        self::$view = preg_replace_callback('/@extends\(\'(.*?)\'\)/', function ($viewName) {
            return self::view($viewName[1], self::$data, true);
        }, self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @yield(section.adi)
     */
    public static function parseYields(): void
    {
        self::$view = preg_replace_callback('/@yield\(\'(.*?)\'\)/', function ($yieldName) {
            return self::$sections[$yieldName[1]] ?? '';
        }, self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @section(section.adi)
     */
    public static function parseSections(): void
    {

        self::$view = preg_replace_callback('/@section\(\'(.*?)\', \'(.*?)\'\)/', function ($sectionDetail) {
            self::$sections[$sectionDetail[1]] = $sectionDetail[2];
            return '';
        }, self::$view);

        self::$view = preg_replace_callback('/@section\(\'(.*?)\'\)(.*?)@endsection/s', function ($sectionName) {
            self::$sections[$sectionName[1]] = $sectionName[2];
            return '';
        }, self::$view);
    }

    /**
     * Yazılan özel direktifleri diziye aktarır
     * @param string $key
     * @param object $callback
     */
    public static function directive(string $key, $callback): void
    {
        self::$directives[$key] = $callback;
    }

    /**
     * Yazılan özel direktifleri parse eder
     */
    public static function customDirectives(): void
    {
        foreach (self::$directives as $key => $callback) {
            self::$view = preg_replace_callback('/@' . $key . '(\(\'(.*?)\'\)|)/', function ($expression) use ($callback) {
                return call_user_func($callback, $expression[2] ?? null);
            }, self::$view);
        }
    }

    /**
     * Aşağıdaki direktifler için parse işlemi yapar
     * @if($expr)
     * @elseif($expr)
     * @else
     */
    public static function parseIfBlocks(): void
    {
        self::$view = preg_replace('/@(else|)if\((.*?)\)/', '<?php $1if ($2): ?>', self::$view);
        self::$view = preg_replace('/@else/', '<?php else: ?>', self::$view);
        self::$view = preg_replace('/@endif/', '<?php endif; ?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @empty($var)
     * @endempty
     */
    public static function parseEmpty(): void
    {
        self::$view = preg_replace_callback('/@empty\((.*?)\)/', function ($expression) {
            return '<?php if (empty(' . $expression[1] . ')): ?>';
        }, self::$view);
        self::$view = preg_replace('/@endempty/', '<?php endif; ?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @isset($var)
     * @endisset
     */
    public static function parseIsset(): void
    {
        self::$view = preg_replace_callback('/@isset\((.*?)\)/', function ($expression) {
            return '<?php if (isset(' . $expression[1] . ')): ?>';
        }, self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @forelse($array as $item)
     * @empty
     * @endforelse
     */
    public static function parseForElse(): void
    {
        self::$view = preg_replace_callback('/@forelse\((.*?)\)/', function ($expression) {
            $data = explode('as', $expression[1]);
            $array = trim($data[0]);
            return '<?php if (isset(' . $array . ') && !empty(' . $array . ')): foreach(' . $expression[1] . '): ?>';
        }, self::$view);

        self::$view = preg_replace('/@empty/', '<?php endforeach; else: ?>', self::$view);

        self::$view = preg_replace('/@endforelse/', '<?php endif; ?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @json($array)
     */
    public static function parseJSON(): void
    {
        self::$view = preg_replace('/@json\((.*?)\)/', '<?=json_encode($1)?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @dump($array)
     */
    public static function parseDump(): void
    {
        self::$view = preg_replace('/@dump\((.*?)\)/', '<?php var_dump($1); ?>', self::$view);
    }

    /**
     * Aşağıdaki direktif için parse işlemi yapar
     * @dd($array)
     */
    public static function parseDd(): void
    {
        self::$view = preg_replace('/@dd\((.*?)\)/', '<?php print_r($1); ?>', self::$view);
    }
}
