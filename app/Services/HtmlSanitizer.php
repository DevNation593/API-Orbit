<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class HtmlSanitizer
{
    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//iframe|//object|//embed|//form|//base|//meta|//link') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//*') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on')
                    || (in_array($name, ['href', 'src', 'action', 'xlink:href'], true)
                        && preg_match('/^(?:javascript|vbscript|data):/i', $value))) {
                    $node->removeAttribute($attribute->name);
                }
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        return collect(iterator_to_array($body->childNodes))
            ->map(fn ($node): string => $document->saveHTML($node) ?: '')
            ->implode('');
    }
}
