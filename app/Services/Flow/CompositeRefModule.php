<?php

namespace App\Services\Flow;

use App\Models\FlowComposite;
use App\Models\FlowCompositeNode;

/**
 * Modulo "virtuale" che rappresenta una chiamata a un sotto-grafo composito.
 *
 * Non è una classe scoperta dal registry via filesystem: viene istanziata
 * dal ModuleRegistry::instantiate() quando la chiave richiesta è un composito.
 * Execute() restituisce `descendCompositeId` → il FlowRunner push'a lo stack
 * e salta al nodo di ingresso del sotto-grafo.
 *
 * Meta/outputs sono costruiti dinamicamente dalle proprietà del composito
 * e dai suoi nodi `composite_output`.
 */
class CompositeRefModule extends Module
{
    public function __construct(
        private readonly FlowComposite $composite,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: $this->composite->key,
            label: $this->composite->label,
            category: $this->composite->category ?? 'composito',
            description: $this->composite->description ?? 'Sotto-grafo riusabile.',
            configSchema: [],
            icon: $this->composite->icon ?? 'package',
        );
    }

    public function outputs(): array
    {
        $exits = FlowCompositeNode::where('composite_id', $this->composite->id)
            ->where('module_key', 'composite_output')
            ->get();

        if ($exits->isEmpty()) {
            return ['out' => 'Continua'];
        }

        $out = [];
        foreach ($exits as $exit) {
            $port  = (string) data_get($exit->config, 'port', 'out');
            $label = (string) (data_get($exit->config, 'label', '') ?: $port);
            $out[$port] = $label;
        }
        return $out;
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        return ModuleResult::descend($this->composite->id);
    }
}
