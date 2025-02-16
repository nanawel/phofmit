<?php

namespace App\PhofmitBundle\Helper;


class DateTime
{
    /**
     * @see https://stackoverflow.com/a/3534705
     * @param int $seconds
     */
    public static function secondsToTime(int $seconds): string {
        $t = round($seconds);

        return sprintf('%02d:%02d:%02d', ($t / 3600), ($t / 60 % 60), $t % 60);
    }
}
