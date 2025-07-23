<?php

namespace zFramework\Core\Facades;

class Response
{
    /**
     * Response type list
     */
    const list = [
        'json' => 'application/json'
    ];

    /**
     * Result Method
     * @param string $type
     * @param array $data
     * @param ?string $flags
     * @return string|mixed
     */
    private static function do(string $type, array $data = [], ?string $flags = null)
    {
        header("Content-Type: " . self::list[$type]);

        switch ($type) {
            case 'json':
                if (config('response.ajax.include-alerts')) $data['alerts'] = Alerts::get();
                $data = json_encode($data, JSON_UNESCAPED_UNICODE | $flags);
                break;
        }

        return $data;
    }

    /**
     * Type Json
     * @param array $data
     * @param ?string $flags
     */
    public static function json(array $data, ?string $flags = null)
    {
        return self::do(__FUNCTION__, $data, $flags);
    }
}
