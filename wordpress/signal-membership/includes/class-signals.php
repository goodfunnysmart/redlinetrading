<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Same 3-way cross logic as engine/lib/Signals.php.
 * buy  = close crossed up through EMA15, yesterday still above EMA65
 * sell = close crossed down through EMA65 (was "exit" in the admin UI)
 * watch = lost EMA15 but still above EMA65  (do not squash into hold)
 * none = everything else
 */
class SIG_Signals {
    /**
     * @return string buy|sell|watch|none
     */
    public static function classify($latestClose, $yesterdayClose, $ema15, $ema65) {
        $latestClose    = (float) $latestClose;
        $yesterdayClose = (float) $yesterdayClose;
        $ema15          = (float) $ema15;
        $ema65          = (float) $ema65;

        if ($latestClose > $ema15 && $yesterdayClose <= $ema15 && $yesterdayClose > $ema65) {
            return 'buy';
        }
        if ($latestClose < $ema65 && $yesterdayClose >= $ema65) {
            return 'sell';
        }
        if ($latestClose <= $ema15 && $latestClose > $ema65 && $yesterdayClose > $ema15) {
            return 'watch';
        }
        return 'none';
    }

    public static function note($signal) {
        switch ($signal) {
            case 'buy':
                return 'Close crossed up through EMA15, still above EMA65';
            case 'sell':
                return 'Close crossed down through EMA65';
            case 'watch':
                return 'Lost EMA15, still above EMA65';
            default:
                return '';
        }
    }

    /**
     * Original radar "under redline" flag: latest close below the 65-day EMA.
     * Same test as redline.php $isBelow65 (📉 when close < EMA65).
     */
    public static function is_under_redline($close, $ema65) {
        if ($close === null || $close === '' || $ema65 === null || $ema65 === '') {
            return false;
        }
        return (float) $close < (float) $ema65;
    }
}
