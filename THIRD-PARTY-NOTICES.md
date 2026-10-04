# Third-party notices

Online Store is released under the **GNU AGPL-3.0**. It uses the following components, installed with Composer
(see `composer.json`) and baked into the Docker image. Each keeps its own licence.

| Component | Use | Licence | Compatible with AGPL-3.0? |
|---|---|---|---|
| [Dompdf](https://github.com/dompdf/dompdf) (and its dependencies: dompdf/php-font-lib, dompdf/php-svg-lib, masterminds/html5, sabberworm/php-css-parser) | PDF invoices | LGPL-2.1 (php-font-lib / php-svg-lib: LGPL-3.0; html5: MIT; css-parser: MIT) | Yes — see below |
| [PHPMailer](https://github.com/PHPMailer/PHPMailer) | Outgoing email | LGPL-2.1-or-later | Yes (an LGPL library may be used by a program under another licence, LGPL §6; the AGPL permits modification and reverse engineering for debugging, as §6 requires) |
| DejaVu Sans (bundled inside Dompdf) | Invoice PDF text | Bitstream Vera licence (free to use, embed and redistribute) | Yes |
| MariaDB, nginx, PHP, phpMyAdmin | Run as separate containers/processes | GPL-2.0 / BSD-2 / PHP-3.01 / GPL-2.0 | Separate programs, not linked into this code |

## PDF invoices (Dompdf)

Earlier drafts used mPDF, which is GPL-2.0-only and cannot be combined with AGPL-3.0 code. It has been replaced by
Dompdf. Dompdf and PHPMailer are LGPL libraries that this program only *uses* (they are installed unmodified by
Composer into `vendor/` and can be replaced by the person running the store), which LGPL §6 allows under any licence
that lets the user modify the combined work and debug it — the AGPL does. If the library is ever missing or fails,
the invoice page falls back to a printable HTML page, so the store keeps working without it.

This file is a technical note, not legal advice.

## Fonts and images

The template ships no third-party fonts or photographs. The header/footer fonts (Fraunces, Inter, IBM Plex Mono)
are loaded from Google Fonts at run time under the SIL Open Font License. Icons are inline SVG written for this project.
Fonts or images that you upload remain under their own licences — make sure you have the right to use them.
