<?php

namespace App\Services;

use App\Models\InboxChannel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class JourneyGraphService
{
    public const TYPES = ['start', 'wait', 'condition', 'email', 'task', 'goal', 'end'];

    public function __construct(private readonly SegmentEngine $segments, private readonly HtmlSanitizer $html) {}

    public function validate(array $graph, string $entityType): array
    {
        $graph = Validator::make(['graph' => $graph], [
            'graph' => ['required', 'array:entry,nodes'],
            'graph.entry' => ['required', 'string', 'max:60'],
            'graph.nodes' => ['required', 'array', 'between:2,100'],
            'graph.nodes.*' => ['required', 'array:id,type,config,next,on_true,on_false,position'],
            'graph.nodes.*.id' => ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,59}$/', 'distinct'],
            'graph.nodes.*.type' => ['required', Rule::in(self::TYPES)],
            'graph.nodes.*.config' => ['sometimes', 'array'],
            'graph.nodes.*.next' => ['nullable', 'string', 'max:60'],
            'graph.nodes.*.on_true' => ['nullable', 'string', 'max:60'],
            'graph.nodes.*.on_false' => ['nullable', 'string', 'max:60'],
            'graph.nodes.*.position' => ['sometimes', 'array:x,y'],
            'graph.nodes.*.position.x' => ['required_with:graph.nodes.*.position', 'numeric', 'between:-100000,100000'],
            'graph.nodes.*.position.y' => ['required_with:graph.nodes.*.position', 'numeric', 'between:-100000,100000'],
        ])->validate()['graph'];
        $nodes = collect($graph['nodes'])->keyBy('id')->all();
        if (($nodes[$graph['entry']]['type'] ?? null) !== 'start' || collect($nodes)->where('type', 'start')->count() !== 1) {
            $this->invalid('The graph requires exactly one start node at entry.');
        }
        foreach ($nodes as $id => &$node) {
            $config = $node['config'] ?? [];
            $type = $node['type'];
            $rules = match ($type) {
                'wait' => ['wait_minutes' => ['required', 'integer', 'between:1,525600']],
                'email' => [
                    'inbox_channel_id' => ['required', 'integer'],
                    'subject' => ['required', 'string', 'max:255'],
                    'body' => ['required', 'string', 'max:100000'],
                    'body_html' => ['nullable', 'string', 'max:100000'],
                ],
                'task' => [
                    'title' => ['required', 'string', 'max:255'],
                    'description' => ['nullable', 'string', 'max:10000'],
                    'due_in_minutes' => ['sometimes', 'integer', 'between:0,525600'],
                    'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
                ],
                'condition', 'goal' => ['definition' => ['required', 'array']],
                default => [],
            };
            if (array_diff(array_keys($config), array_keys($rules)) !== []) {
                $this->invalid('Unsupported node configuration at '.$id.'.');
            }
            $node['config'] = Validator::make($config, $rules)->validate();
            if (in_array($type, ['condition', 'goal'], true)) {
                $this->segments->query($entityType, $config['definition']);
            }
            if ($type === 'email') {
                if (! InboxChannel::whereKey($config['inbox_channel_id'])->where('channel', 'email')->where('status', 'active')->exists()) {
                    $this->invalid('Email nodes require an active email channel in this tenant.');
                }
                if (isset($config['body_html'])) {
                    $node['config']['body_html'] = $this->html->sanitize($config['body_html']);
                }
            }
            $edges = $this->edges($node);
            if (($type === 'condition' && count($edges) !== 2) ||
                (! in_array($type, ['condition', 'end'], true) && count($edges) !== 1)) {
                $this->invalid('Each nonterminal node must define its outgoing edges.');
            }
            if (($type === 'end' && (filled($node['next'] ?? null) || filled($node['on_true'] ?? null) || filled($node['on_false'] ?? null))) ||
                ($type === 'condition' && filled($node['next'] ?? null)) ||
                ($type !== 'condition' && (filled($node['on_true'] ?? null) || filled($node['on_false'] ?? null)))) {
                $this->invalid('Unexpected outgoing edge for node '.$id.'.');
            }
            foreach ($edges as $edge) {
                if (! isset($nodes[$edge])) {
                    $this->invalid('An outgoing edge references a missing node.');
                }
            }
        }
        unset($node);
        $visited = [];
        $stack = [];
        $walk = function (string $id) use (&$walk, &$visited, &$stack, $nodes): void {
            if (isset($stack[$id])) {
                $this->invalid('Journey graphs must not contain cycles.');
            }
            if (isset($visited[$id])) {
                return;
            }
            $stack[$id] = true;
            foreach ($this->edges($nodes[$id]) as $next) {
                $walk($next);
            }
            unset($stack[$id]);
            $visited[$id] = true;
        };
        $walk($graph['entry']);
        if (count($visited) !== count($nodes)) {
            $this->invalid('Every node must be reachable from entry.');
        }
        $graph['nodes'] = array_values($nodes);

        return $graph;
    }

    private function edges(array $node): array
    {
        return array_values(array_filter(
            $node['type'] === 'condition' ? [$node['on_true'] ?? null, $node['on_false'] ?? null]
                : ($node['type'] === 'end' ? [] : [$node['next'] ?? null]),
            fn ($value) => is_string($value) && $value !== '',
        ));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['graph' => $message]);
    }
}
