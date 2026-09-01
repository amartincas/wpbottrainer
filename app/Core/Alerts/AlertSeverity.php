<?php

namespace App\Core\Alerts;

/**
 * Closed, finite scale — unlike `category` (string libre, ver Alert.php),
 * la severidad sí es un concepto genuinamente cerrado.
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
