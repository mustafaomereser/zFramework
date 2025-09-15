<?php

namespace zFramework\Core\Helpers;

class Date
{
    /**
     * Set Timezone
     * @param string $set
     * @return int
     */
    public static function setLocale(string $set): int
    {
        return date_default_timezone_set($set);
    }

    /**
     * Get Current Timezone
     * @return string
     */
    public static function locale(): string
    {
        return date_default_timezone_get();
    }

    /**
     * Date reformat
     * @param string|int $date
     * @param string $format
     * @return string 
     */
    public static function format(?string $date = null, string $format = 'd.m.Y'): string
    {
        return $date ? date($format, (is_string($date) ? strtotime($date) : $date)) : '-';
    }

    /**
     * Get Now With Date
     * @param string $format
     * @return string
     */
    public static function now(string $format = 'd.m.Y H:i'): string
    {
        return date($format);
    }

    /**
     * Current Timestamp For Mysql
     * @param string $strtotime
     * @return string
     */
    public static function timestamp(string $strtotime = '-0 days'): string
    {
        return date('Y-m-d H:i:s', strtotime($strtotime));
    }

    /**
     * TimeAgo
     * @param string|int $date
     * @return string
     */
    public static function timeago($date)
    {
        $time = time() - strtotime($date); // to get the time since that moment
        $time = ($time < 1) ? 1 : $time;
        $tokens = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
            1 => 'second'
        ];

        foreach ($tokens as $unit => $text) {
            if ($time < $unit) continue;
            $numberOfUnits = floor($time / $unit);
            return $numberOfUnits . ' ' . $text . (($numberOfUnits > 1) ? 's' : '');
        }
    }

    /**
     * TimeDiff
     * @param int $time1
     * @param int $time2
     * @return string
     */
    public static function timediff($time1, $time2)
    {
        $diff = abs($time2 - $time1);

        $output  = [];
        $output['years']   = floor($diff / (365 * 60 * 60 * 24));
        $output['months']  = floor(($diff - $output['years'] * 365 * 60 * 60 * 24) / (30 * 60 * 60 * 24));
        $output['days']    = floor(($diff - $output['years'] * 365 * 60 * 60 * 24 - $output['months'] * 30 * 60 * 60 * 24) / (60 * 60 * 24));
        $output['hours']   = floor(($diff - $output['years'] * 365 * 60 * 60 * 24 - $output['months'] * 30 * 60 * 60 * 24 - $output['days'] * 60 * 60 * 24) / (60 * 60));
        $output['minuts']  = floor(($diff - $output['years'] * 365 * 60 * 60 * 24 - $output['months'] * 30 * 60 * 60 * 24 - $output['days'] * 60 * 60 * 24 - $output['hours'] * 60 * 60) / 60);
        $output['seconds'] = floor(($diff - $output['years'] * 365 * 60 * 60 * 24 - $output['months'] * 30 * 60 * 60 * 24 - $output['days'] * 60 * 60 * 24 - $output['hours'] * 60 * 60 - $output['minuts'] * 60));
        foreach ($output as $key => $value) if (!$value) unset($output[$key]);
        return $output;
    }
}
