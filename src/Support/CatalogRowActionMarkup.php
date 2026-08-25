<?php

namespace Kazaminosuke\ModManager\Support;

/**
 * Compact catalog row-action markup. Filament's icon-button HTML repeats a
 * full SVG, Alpine tooltip, loading indicator, wire:target, and color
 * class map on every row. Catalog tabs render 3 actions × 20 rows, so that
 * duplication dominates Livewire's effects.html. The action's Livewire
 * handler, modal, and authorize() still come from CatalogRowAction.
 */
final class CatalogRowActionMarkup
{
    /**
     * @param  array{name: string, color: string, label: string, disabled?: bool, wireClick?: string|null, wireKey?: string|null}  $action
     */
    public static function button(array $action): string
    {
        $name = $action['name'];
        $color = $action['color'];
        $label = $action['label'];
        $disabled = (bool) ($action['disabled'] ?? false);
        $wireClick = $action['wireClick'] ?? null;
        $wireKey = $action['wireKey'] ?? null;

        // Match the pre-compact table record action: Filament defaults those
        // buttons to Size::Small. Tabler SVGs use class fi-size-xl; on this
        // Panel that class computes to 1.75rem even though the SVG attributes
        // are width/height 24.
        $classes = 'fi-icon-btn fi-ac-icon-btn-action fi-size-sm mmr-row-action';
        if ($disabled) {
            $classes .= ' fi-disabled';
        }

        $attributes = [
            'type' => 'button',
            'class' => $classes,
            'title' => $label,
            'aria-label' => $label,
            'data-mmr-swr-row-action' => $name,
            'data-mmr-swr-row-action-color' => $color,
        ];

        if ($disabled) {
            $attributes['disabled'] = 'disabled';
        } elseif (is_string($wireClick) && $wireClick !== '') {
            $attributes['wire:click'] = $wireClick;
        }

        if (is_string($wireKey) && $wireKey !== '') {
            $attributes['wire:key'] = $wireKey;
        }

        $html = '<button';
        foreach ($attributes as $attribute => $value) {
            $html .= ' '.$attribute.'="'.e($value).'"';
        }

        return $html.'><span class="fi-icon fi-size-xl mmr-row-action-icon" aria-hidden="true"></span></button>';
    }
}
