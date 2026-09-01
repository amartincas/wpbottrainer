<?php

namespace App\Payments\Enums;

/**
 * El *mecanismo* de confirmación — genuinamente cerrado y finito: o hay un
 * humano revisando un comprobante, o hay una pasarela como autoridad
 * automática. El *nombre* del método (Nequi, Daviplata, PSE...) es libre
 * (Payment.method_label) — mismo patrón ya usado en Alert (severidad
 * cerrada + categoría libre). Ver docs/DECISIONS.md.
 */
enum PaymentMethodType: string
{
    case ManualTransfer = 'manual_transfer';
    case Gateway = 'gateway';
}
