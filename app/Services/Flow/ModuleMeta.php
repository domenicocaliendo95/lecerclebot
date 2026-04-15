<?php

namespace App\Services\Flow;

/**
 * Metadati di un modulo, esposti all'editor visuale tramite /api/admin/flow/modules.
 *
 * `configSchema` è un array di campi editabili; ogni campo è un dict con:
 *   - type: string|text|int|bool|select|message_ref|button_list|key_value|code
 *   - label: string — etichetta visibile
 *   - required: bool (opt)
 *   - default: mixed (opt)
 *   - options: array (solo per select) — lista di {value,label}
 *   - help: string (opt) — descrizione sotto il campo
 */
class ModuleMeta
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category,     // trigger|logica|invio|attesa|ai|dati|azione
        public readonly string $description,
        public readonly array  $configSchema = [],
        public readonly string $icon = 'box',
    ) {}

    public function toArray(): array
    {
        return [
            'key'          => $this->key,
            'label'        => $this->label,
            'category'     => $this->category,
            'description'  => $this->description,
            'config_schema'=> $this->configSchema,
            'icon'         => $this->icon,
        ];
    }
}
