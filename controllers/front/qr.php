<?php
/**
 * Serves the QR image (PNG, SVG fallback) for a single order, authorized by an
 * unguessable token derived from the order's secure key. Used as a hosted image
 * URL in the order-confirmation e-mail.
 *
 * @license MIT
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class QrplatbaQrModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    public function postProcess(): void
    {
        $idOrder = (int) Tools::getValue('id_order');
        $token = (string) Tools::getValue('token');

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || $order->module !== 'qrplatba') {
            $this->notFound();
        }

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)
            || !hash_equals(Qrplatba::qrToken($idOrder, $customer->secure_key), $token)) {
            $this->notFound();
        }

        /** @var Qrplatba $module */
        $module = $this->module;
        $result = $module->qrResultFor($order);

        // Preferred: PNG (mail clients / <img> everywhere).
        $png = $module->makeGenerator()->renderPngString($result['spayd']);
        if ($png !== null && $png !== '') {
            $this->output($png, 'image/png');
        }

        // Fallback: the SVG already built for the on-page view.
        $dataUri = $result['data_uri'] ?? null;
        if ($dataUri) {
            $comma = strpos($dataUri, ',');
            if ($comma !== false) {
                $payload = substr($dataUri, $comma + 1);
                $svg = strpos($dataUri, ';base64,') !== false
                    ? (string) base64_decode($payload)
                    : rawurldecode($payload);
                $this->output($svg, 'image/svg+xml');
            }
        }

        $this->notFound();
    }

    private function output(string $body, string $mime): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: private, max-age=0, no-store');
        echo $body;
        exit;
    }

    private function notFound(): void
    {
        header('HTTP/1.1 404 Not Found');
        exit;
    }
}
