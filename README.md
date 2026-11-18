<p align="center">
  <img src="logo.png" alt="QR Platba" width="72" height="72">
</p>

# QR Platba — PrestaShop payment module

Bank-transfer payment for Czech shops with a **QR Platba (SPAYD)** code, so customers
can pay by scanning it in their mobile banking app.

The QR is shown on the order-confirmation page, in the customer's account (order
detail), and in the "waiting for payment" e-mail — together with the plain payment
details (account / IBAN, amount, variable symbol).

## Features

- Adds a **"Bankovní převod (QR platba)"** payment option at checkout.
- QR + payment details on the confirmation page, in the customer account, and in the
  e-mail (inline image **and** `qr-platba.png` attachment).
- Variable symbol = order number, amount = order total, optional due date.
- Configure a domestic **account number + bank code** or a full **IBAN**, plus
  beneficiary, bank address, constant symbol, message prefix and due days.

## Requirements

- PrestaShop **8.0 – 9.x** (verified on 9.2)
- PHP **≥ 8.1** (verified on 8.5)
- PHP **GD** extension (for the e-mail QR image)

## Installation

1. Copy the `qrplatba/` folder into your shop's `modules/` directory.
2. Install the dependencies:
   ```bash
   cd modules/qrplatba
   composer install
   ```
3. In **Back office → Modules**, install **QR Platba**.
4. Open its configuration and fill in your **account number + bank code** (or **IBAN**)
   and **beneficiary**.
5. Under **Modules → Payment → Currency restrictions**, enable the module for **CZK**.

## Configuration

| Setting | Description |
| --- | --- |
| Název platby | Label shown at checkout |
| Příjemce | Account holder / beneficiary name |
| Sídlo banky | Bank address (optional, shown in the e-mail) |
| IBAN | Full IBAN — takes precedence over account + bank code |
| Číslo účtu / Kód banky | Domestic account number and bank code |
| Konstantní symbol | Optional constant symbol |
| Prefix zprávy | Prefix for the payment message |
| Splatnost (dní) | Due date in days |

> Use a **real** account number — a QR built from an invalid Czech account/bank code is
> rejected by banking apps (offered as a foreign payment).

## Updating

Replace the module files and open the Back office — PrestaShop upgrades the module
automatically and keeps your configuration. No uninstall/reinstall needed.

## Built with

- [RikudouSage/QrPaymentCZ](https://github.com/RikudouSage/QrPaymentCZ)
  (`rikudou/czqrpayment`) — Czech SPAYD payment string
- [endroid/qr-code](https://github.com/endroid/qr-code) — QR image rendering

Both are MIT-licensed.

## License

[MIT](LICENSE)
