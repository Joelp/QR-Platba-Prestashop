<?php
/**
 * QR Platba — Czech bank-transfer payment with a SPAYD QR code for PrestaShop 9.
 *
 * Payment flow modelled on PrestaShop/ps_wirepayment; the QR / SPAYD string is
 * produced by RikudouSage/QrPaymentCZ (rikudou/czqrpayment) with a native
 * fallback — see classes/QrPlatbaGenerator.php.
 *
 * @author    Joelp <joelp@email.cz>
 * @copyright Joelp
 * @license   MIT
 */

declare(strict_types=1);

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Joelp\QrPlatba\QrPlatbaGenerator;

if (!defined('_PS_VERSION_')) {
    exit;
}

// Composer deps (rikudou/czqrpayment + a QR image provider), when installed.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
// Our generator is loaded manually so it works even without a composer dump.
require_once __DIR__ . '/classes/QrPlatbaGenerator.php';

class Qrplatba extends PaymentModule
{
    /** @var string[] configuration keys with their defaults */
    const CONFIG = [
        'QRPLATBA_IBAN' => '',
        'QRPLATBA_ACCOUNT' => '',
        'QRPLATBA_BANK_CODE' => '',
        'QRPLATBA_BENEFICIARY' => '',
        'QRPLATBA_BANK_ADDRESS' => '',
        'QRPLATBA_CONST_SYMBOL' => '',
        'QRPLATBA_DUE_DAYS' => '14',
        'QRPLATBA_MESSAGE_PREFIX' => 'Objednavka',
        'QRPLATBA_TITLE' => 'Bankovní převod (QR platba)',
    ];

    public function __construct()
    {
        $this->name = 'qrplatba';
        $this->tab = 'payments_gateways';
        $this->version = '0.2.7';
        $this->author = 'Joelp';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['validation', 'qr'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('QR Platba', [], 'Modules.Qrplatba.Admin');
        $this->description = $this->trans(
            'Platba bankovním převodem s českým QR kódem (SPAYD / QR Platba) na stránce potvrzení objednávky.',
            [],
            'Modules.Qrplatba.Admin'
        );
        $this->confirmUninstall = $this->trans('Opravdu odinstalovat QR Platbu?', [], 'Modules.Qrplatba.Admin');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->trans('Pro tento modul není nastavena žádná měna.', [], 'Modules.Qrplatba.Admin');
        }
    }

    public function install(): bool
    {
        if (!parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('displayOrderDetail')
            || !$this->registerHook('displayHeader')
            || !$this->registerHook('actionEmailSendBefore')
        ) {
            return false;
        }

        foreach (self::CONFIG as $key => $default) {
            Configuration::updateValue($key, $default);
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach (array_keys(self::CONFIG) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    // ------------------------------------------------------------------ hooks

    public function hookDisplayHeader(): void
    {
        $this->context->controller->registerStylesheet(
            'qrplatba-css',
            'modules/' . $this->name . '/views/css/qrplatba.css',
            ['media' => 'all', 'priority' => 150]
        );
    }

    public function hookPaymentOptions($params): array
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText(Configuration::get('QRPLATBA_TITLE') ?: $this->displayName)
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:qrplatba/views/templates/hook/payment_intro.tpl'));

        return [$option];
    }

    /** Order-confirmation page: show the QR + payment details. */
    public function hookDisplayPaymentReturn($params): string
    {
        return $this->renderQrBlock($params['order'] ?? null);
    }

    /** Customer account → order detail: repeat the QR so they can pay later. */
    public function hookDisplayOrderDetail($params): string
    {
        $order = $params['order'] ?? null;

        // Only show while still awaiting payment.
        if ($order instanceof Order
            && (int) $order->getCurrentState() !== (int) Configuration::get('PS_OS_BANKWIRE')) {
            return '';
        }

        return $this->renderQrBlock($order);
    }

    /**
     * Render the QR + payment details for an order. Never throws: PrestaShop
     * swallows hook exceptions silently (blank output), so any failure here is
     * caught and logged instead of leaving the merchant with a mysteriously
     * missing QR.
     *
     * @param mixed $order
     */
    private function renderQrBlock($order): string
    {
        if (!$this->active || !($order instanceof Order) || $order->module !== $this->name) {
            return '';
        }

        try {
            $this->smarty->assign($this->buildTemplateVars($order));

            return $this->fetch('module:qrplatba/views/templates/hook/payment_return.tpl');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('qrplatba render failed: ' . $e->getMessage(), 3);

            return '';
        }
    }

    /**
     * Put the QR into the "bankwire" (waiting-for-payment) e-mail only: payment
     * details (domestic account + IBAN + amount + VS) and the QR go into the
     * {bankwire_*} slots (each rendered once), with the PNG also attached.
     * order_conf is left untouched. Never breaks e-mail sending.
     *
     * @param array $params passed by reference by Mail::send()
     */
    public function hookActionEmailSendBefore($params): bool
    {
        try {
            // Only the bankwire ("waiting for payment") e-mail carries the QR.
            // order_conf is left untouched.
            if (($params['template'] ?? null) !== 'bankwire'
                || !is_array($params['templateVars'] ?? null)) {
                return true;
            }

            $idOrder = (int) ($params['templateVars']['{id_order}'] ?? 0);
            if ($idOrder <= 0) {
                return true;
            }

            $order = new Order($idOrder);
            if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
                return true;
            }

            $vars = $this->buildTemplateVars($order);
            $png = $this->makeGenerator()->renderPngString($vars['qr_spayd']);

            // Czech domestic account number (účet/kód banky) + IBAN, then the QR.
            $details = '';
            if (!empty($vars['qr_account'])) {
                $account = $vars['qr_account']
                    . (!empty($vars['qr_bank_code']) ? '/' . $vars['qr_bank_code'] : '');
                $details .= $this->trans('Číslo účtu', [], 'Modules.Qrplatba.Shop') . ': ' . $account . '<br>';
            }
            $details .= 'IBAN: ' . $vars['qr_iban']
                . '<br>' . $this->trans('Částka', [], 'Modules.Qrplatba.Shop') . ': ' . $vars['qr_amount']
                . '<br>' . $this->trans('Variabilní symbol', [], 'Modules.Qrplatba.Shop') . ': ' . $vars['qr_vs'];

            // Inline the QR as a base64 PNG data-URI. Unlike a hosted <img>, this
            // renders offline and in clients that block remote content (Thunderbird,
            // Apple Mail, Seznam…). Clients that strip data-URIs (Gmail, Outlook)
            // fall back to the attachment below.
            if ($png !== null) {
                $details .= '<br><br><img src="data:image/png;base64,' . base64_encode($png)
                    . '" alt="QR Platba" width="200" height="200" style="border:1px solid #e5e5e5;">';
            }

            // {bankwire_owner} = "Majitel účtu", {bankwire_details} = "Podrobnosti",
            // {bankwire_address} = "Sídlo banky" (configurable; empty if unset).
            $params['templateVars']['{bankwire_owner}'] = $vars['qr_beneficiary'];
            $params['templateVars']['{bankwire_details}'] = $details;
            $params['templateVars']['{bankwire_address}'] = Tools::nl2br(
                (string) Configuration::get('QRPLATBA_BANK_ADDRESS')
            );

            // Attach the PNG too — always delivered; the fallback where data-URIs are stripped.
            if ($png !== null) {
                $attachment = ['content' => $png, 'name' => 'qr-platba.png', 'mime' => 'image/png'];
                if (empty($params['fileAttachment'])) {
                    $params['fileAttachment'] = $attachment;
                } elseif (isset($params['fileAttachment']['content'])) {
                    $params['fileAttachment'] = [$params['fileAttachment'], $attachment];
                } elseif (is_array($params['fileAttachment'])) {
                    $params['fileAttachment'][] = $attachment;
                }
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('qrplatba e-mail inject failed: ' . $e->getMessage(), 3);
        }

        return true;
    }

    // --------------------------------------------------------------- helpers

    public function checkCurrency($cart): bool
    {
        $currencyOrder = new Currency($cart->id_currency);
        $modulesCurrencies = $this->getCurrency($cart->id_currency);

        if (is_array($modulesCurrencies)) {
            foreach ($modulesCurrencies as $currencyModule) {
                if ($currencyOrder->id == $currencyModule['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Assemble everything the payment template needs from an Order.
     */
    /** Register any hook this version needs that isn't registered yet (idempotent). */
    private function ensureHooks(): void
    {
        $hooks = [
            'paymentOptions',
            'displayPaymentReturn',
            'displayOrderDetail',
            'displayHeader',
            'actionEmailSendBefore',
        ];
        foreach ($hooks as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
            }
        }
    }

    /** Build a configured generator from the module settings. */
    public function makeGenerator(): QrPlatbaGenerator
    {
        return new QrPlatbaGenerator(
            (string) Configuration::get('QRPLATBA_IBAN') ?: null,
            (string) Configuration::get('QRPLATBA_ACCOUNT'),
            (string) Configuration::get('QRPLATBA_BANK_CODE')
        );
    }

    /** Run the generator for a given order (SPAYD + image). */
    public function qrResultFor(Order $order): array
    {
        $currency = new Currency($order->id_currency);
        $prefix = (string) Configuration::get('QRPLATBA_MESSAGE_PREFIX');

        return $this->makeGenerator()->build(
            (int) $order->id,
            (float) $order->total_paid,
            $currency->iso_code,
            trim($prefix . ' ' . $order->reference),
            (int) Configuration::get('QRPLATBA_DUE_DAYS')
        );
    }

    /** Unguessable token for the hosted QR image URL (used in e-mails). */
    public static function qrToken(int $idOrder, string $secureKey): string
    {
        return sha1($idOrder . '-' . $secureKey . '-' . _COOKIE_KEY_);
    }

    private function buildTemplateVars(Order $order): array
    {
        $currency = new Currency($order->id_currency);
        $amount = (float) $order->total_paid;
        $vs = (int) $order->id;

        $result = $this->qrResultFor($order);

        return [
            'qr_data_uri' => $result['data_uri'],
            'qr_spayd' => $result['spayd'],
            'qr_iban' => $result['iban'],
            'qr_image_missing' => $result['data_uri'] === null,
            'qr_beneficiary' => Configuration::get('QRPLATBA_BENEFICIARY'),
            'qr_account' => Configuration::get('QRPLATBA_ACCOUNT'),
            'qr_bank_code' => Configuration::get('QRPLATBA_BANK_CODE'),
            'qr_amount' => $this->context->getCurrentLocale()->formatPrice($amount, $currency->iso_code),
            'qr_currency' => $currency->iso_code,
            'qr_vs' => $vs,
            'qr_const_symbol' => Configuration::get('QRPLATBA_CONST_SYMBOL'),
            'qr_reference' => $order->reference,
        ];
    }

    // ----------------------------------------------------------- admin config

    public function getContent(): string
    {
        $output = '';

        // Self-heal: re-register any hook added in a newer version, so a plain
        // file update (no reinstall) still wires everything up.
        $this->ensureHooks();

        if (Tools::isSubmit('submitQrplatba')) {
            foreach (array_keys(self::CONFIG) as $key) {
                Configuration::updateValue($key, trim((string) Tools::getValue($key)));
            }
            $output .= $this->displayConfirmation($this->trans('Nastavení uloženo.', [], 'Modules.Qrplatba.Admin'));
        }

        // Surface the dependency state so the merchant knows if the QR image works.
        $gen = new QrPlatbaGenerator(null, '1', '0800');
        if (!$gen->libraryAvailable()) {
            $output .= $this->displayWarning($this->trans(
                'Knihovna rikudou/czqrpayment není nainstalována — SPAYD řetězec se generuje nativně, ale QR obrázek se nevykreslí. Spusťte v adresáři modulu: composer install',
                [],
                'Modules.Qrplatba.Admin'
            ));
        } elseif (!$gen->imageAvailable()) {
            $output .= $this->displayWarning($this->trans(
                'Chybí QR image provider (např. endroid/qr-code). Spusťte: composer require endroid/qr-code',
                [],
                'Modules.Qrplatba.Admin'
            ));
        }

        return $output . $this->renderForm();
    }

    private function renderForm(): string
    {
        $fields = [
            ['type' => 'text', 'label' => $this->trans('Název platby', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_TITLE', 'required' => true],
            ['type' => 'text', 'label' => $this->trans('Příjemce (název účtu)', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_BENEFICIARY'],
            ['type' => 'textarea', 'label' => $this->trans('Sídlo banky', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_BANK_ADDRESS', 'rows' => 3, 'desc' => $this->trans('Nepovinné. Zobrazí se v e-mailu „Čeká se na platbu".', [], 'Modules.Qrplatba.Admin')],
            ['type' => 'text', 'label' => $this->trans('IBAN (nepovinné, má přednost)', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_IBAN', 'desc' => $this->trans('Pokud vyplníte IBAN, číslo účtu a kód banky se ignorují.', [], 'Modules.Qrplatba.Admin')],
            ['type' => 'text', 'label' => $this->trans('Číslo účtu', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_ACCOUNT', 'desc' => $this->trans('Např. 19-2000145399 (předčíslí je nepovinné).', [], 'Modules.Qrplatba.Admin')],
            ['type' => 'text', 'label' => $this->trans('Kód banky', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_BANK_CODE', 'desc' => $this->trans('Např. 0800.', [], 'Modules.Qrplatba.Admin')],
            ['type' => 'text', 'label' => $this->trans('Konstantní symbol', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_CONST_SYMBOL'],
            ['type' => 'text', 'label' => $this->trans('Prefix zprávy pro příjemce', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_MESSAGE_PREFIX'],
            ['type' => 'text', 'label' => $this->trans('Splatnost (dní)', [], 'Modules.Qrplatba.Admin'), 'name' => 'QRPLATBA_DUE_DAYS'],
        ];

        $form = [
            'form' => [
                'legend' => ['title' => $this->trans('Nastavení QR Platby', [], 'Modules.Qrplatba.Admin'), 'icon' => 'icon-qrcode'],
                'input' => $fields,
                'submit' => ['title' => $this->trans('Uložit', [], 'Modules.Qrplatba.Admin')],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitQrplatba';
        $helper->default_form_language = (int) $this->context->language->id;

        $values = [];
        foreach (array_keys(self::CONFIG) as $key) {
            $values[$key] = Tools::getValue($key, Configuration::get($key));
        }
        $helper->tpl_vars = ['fields_value' => $values];

        return $helper->generateForm([$form]);
    }
}
