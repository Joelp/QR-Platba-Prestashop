<?php
/**
 * 0.2.0 — QR in the order-confirmation e-mail: register the new hook used to
 * inject the QR image + payment details into order_conf.
 *
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_0($module)
{
    return $module->registerHook('actionEmailSendBefore');
}
