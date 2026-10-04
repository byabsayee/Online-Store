# Third-party notices

Online Store is released under the **GNU AGPL-3.0**. It uses the following components, installed with Composer
(see `composer.json`) and baked into the Docker image. Each keeps its own licence.

| Component | Use | Licence | Compatible with AGPL-3.0? |
|---|---|---|---|
| [mPDF](https://github.com/mpdf/mpdf) (and its dependencies: setasign/fpdi, psr/log, myclabs/deep-copy, paragonie/random_compat, …) | PDF invoices | GPL-2.0-only | **Needs checking — see below** |
| [PHPMailer](https://github.com/PHPMailer/PHPMailer) | Outgoing email | LGPL-2.1-or-later | Yes (LGPL-2.1+ may be combined with AGPL-3.0 under LGPL §3 / GPLv3 compatibility) |
| MariaDB, nginx, PHP, phpMyAdmin | Run as separate containers/processes | GPL-2.0 / BSD-2 / PHP-3.01 / GPL-2.0 | Separate programs, not linked into this code |

## mPDF — action needed before publishing

mPDF is distributed under **GPL-2.0-only**. GPL-2.0-only code **cannot** be combined into one work with
AGPL-3.0 code (the licences are not compatible in that direction). mPDF is loaded as a Composer library, so the
combination is a real licensing question, not just a formality. Before you publish the template widely, choose one:

1. Replace mPDF with a library under a compatible licence (for example Dompdf, LGPL-2.1, or TCPDF, LGPL-3.0), or
2. Make PDF invoices an optional, separately installed add-on that is not part of the distributed AGPL work, or
3. Ask a lawyer who knows open-source licensing to confirm your use.

This file is a technical note, not legal advice.

## Fonts and images

The template ships no third-party fonts or photographs. The header/footer fonts (Fraunces, Inter, IBM Plex Mono)
are loaded from Google Fonts at run time under the SIL Open Font License. Icons are inline SVG written for this project.
Fonts or images that you upload remain under their own licences — make sure you have the right to use them.
