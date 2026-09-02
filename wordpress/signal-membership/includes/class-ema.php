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
}
