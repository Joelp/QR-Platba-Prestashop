{*
 * QR + bank-transfer details, shown on the order-confirmation page and in the
 * customer's order detail while the order awaits payment.
 * @license MIT
 *}
<div class="qrplatba-box card">
    <div class="card-block">
        <h3 class="h3 card-title">{l s='Zaplaťte bankovním převodem' d='Modules.Qrplatba.Shop'}</h3>

        <div class="qrplatba-grid">
            {if $qr_data_uri}
                <div class="qrplatba-qr">
                    <img src="{$qr_data_uri}" alt="{l s='QR Platba' d='Modules.Qrplatba.Shop'}" width="220" height="220">
                    <p class="qrplatba-hint">{l s='Naskenujte v mobilní bankovní aplikaci' d='Modules.Qrplatba.Shop'}</p>
                </div>
            {/if}

            <div class="qrplatba-details">
                <table class="table">
                    {if $qr_beneficiary}
                        <tr><th>{l s='Příjemce' d='Modules.Qrplatba.Shop'}</th><td>{$qr_beneficiary}</td></tr>
                    {/if}
                    {if $qr_account}
                        <tr><th>{l s='Číslo účtu' d='Modules.Qrplatba.Shop'}</th><td>{$qr_account}{if $qr_bank_code}/{$qr_bank_code}{/if}</td></tr>
                    {/if}
                    <tr><th>IBAN</th><td>{$qr_iban}</td></tr>
                    <tr><th>{l s='Částka' d='Modules.Qrplatba.Shop'}</th><td><strong>{$qr_amount}</strong></td></tr>
                    <tr><th>{l s='Variabilní symbol' d='Modules.Qrplatba.Shop'}</th><td>{$qr_vs}</td></tr>
                    {if $qr_const_symbol}
                        <tr><th>{l s='Konstantní symbol' d='Modules.Qrplatba.Shop'}</th><td>{$qr_const_symbol}</td></tr>
                    {/if}
                    <tr><th>{l s='Zpráva pro příjemce' d='Modules.Qrplatba.Shop'}</th><td>{$qr_reference}</td></tr>
                </table>

                {if $qr_image_missing}
                    <p class="qrplatba-spayd small text-muted">{l s='Platební řetězec' d='Modules.Qrplatba.Shop'}: <code>{$qr_spayd}</code></p>
                {/if}
            </div>
        </div>
    </div>
</div>
