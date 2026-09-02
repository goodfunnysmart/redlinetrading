<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Same SMA-seed EMA as engine/lib/Ema.php so REST charts cannot drift. */
class SIG_Ema {
    public static $periods = array(15, 25, 35, 45, 55, 65);

    public static function series($closes, $period) {
        $period = (int) $period;
        $n = count($closes);
        if ($period < 1 || $n < $period) {
            return null;
        }
        $k = 2.0 / ($period + 1.0);
        $one = 1.0 - $k;
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += (float) $closes[$i];
        }
        $ema = $sum / $period;
        $out[$period - 1] = $ema;
        for ($i = $period; $i < $n; $i++) {
            $ema = ((float) $closes[$i]) * $k + $ema * $one;
            $out[$i] = $ema;
        }
        return $out;
    }

    public static function ribbon($closes) {
        $ribbon = array();
        foreach (self::$periods as $per) {
            $ribbon[$per] = self::series($closes, $per);
        }
        return $ribbon;
    }

    /**
     * Wilder RSI. Available for charts; signal rules stay BUY/SELL/WATCH/UNDER.
     */
    public static function rsi($closes, $period = 14) {
        $period = (int) $period;
        $n = count($closes);
        if ($period < 1 || $n < $period + 1) {
            return null;
        }
        $out = array_fill(0, $n, null);
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $delta = (float) $closes[$i] - (float) $closes[$i - 1];
            if ($delta >= 0) {
                $gain += $delta;
            } else {
                $loss -= $delta;
            }
        }
        $avg_gain = $gain / $period;
        $avg_loss = $loss / $period;
        if ($avg_loss == 0.0) {
            $out[$period] = 100.0;
        } else {
            $rs = $avg_gain / $avg_loss;
            $out[$period] = 100.0 - (100.0 / (1.0 + $rs));
        }
        for ($i = $period + 1; $i < $n; $i++) {
            $delta = (float) $closes[$i] - (float) $closes[$i - 1];
            $g = ($delta > 0) ? $delta : 0.0;
            $l = ($delta < 0) ? -$delta : 0.0;
            $avg_gain = (($avg_gain * ($period - 1)) + $g) / $period;
            $avg_loss = (($avg_loss * ($period - 1)) + $l) / $period;
            if ($avg_loss == 0.0) {
                $out[$i] = 100.0;
            } else {
                $rs = $avg_gain / $avg_loss;
                $out[$i] = 100.0 - (100.0 / (1.0 + $rs));
            }
        }
        return $out;
    }
}
