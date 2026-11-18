<?php
/**
 * Creates the order for the QR Platba option, then redirects to the
 * order-confirmation page (where hookDisplayPaymentReturn renders the QR).
 * Mirrors PrestaShop/ps_wirepayment's validation controller.
 *
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class QrplatbaValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Confirm qrplatba is actually an enabled payment module.
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === 'qrplatba') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            throw new PrestaShopException('Tento platební modul není dostupný.');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        $this->module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PS_OS_BANKWIRE'),
            $total,
            $this->module->displayName,
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, (int) $this->context->language->id, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $this->module->currentOrder,
            'key' => $customer->secure_key,
        ]));
    }
}
