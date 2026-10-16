<?php

namespace App\Confirmations\WhatsApp;

/**
 * Order of the numbered body variables ({{1}}..{{5}}) in the confirmation templates.
 * The sender fills them in this order; TemplateDefinitions numbers its copy the same way.
 */
final class TemplateParams
{
    public const BODY = ['first_name', 'order_number', 'items', 'total', 'payment'];
}
