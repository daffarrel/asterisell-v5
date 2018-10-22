<?php

// SPDX-License-Identifier: GPL-3.0-or-later

sfLoader::loadHelpers(array('I18N', 'Debug', 'Date', 'Asterisell'));

/**
 * Use GenerateInvoiceUsingTemplate01PDF for generating a call report similar to an invoice,
 * but that is not a legal invoice.
 */
class GenerateNoLegalInvoiceUsingTemplate01PDF extends GenerateInvoiceUsingTemplate01PDF {

    public function deriveReportParams() {
        $this->setIsLegal(false);
        parent::deriveReportParams();
    }
}
