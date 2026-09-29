<?php

/*
| design.md D16: the token block of the visual proposal (§4.1 "Tailwind v4 +
| shadcn (CSS)") is ported as-is into the app stylesheet. This guards the
| port: every light (`:root`) and dark (`.dark`) color token of the proposal
| must exist in resources/css/app.css with the same value.
*/

/**
 * @return array<string, string>
 */
function cssVariables(string $block): array
{
    preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $block, $matches, PREG_SET_ORDER);

    $variables = [];

    foreach ($matches as [, $name, $value]) {
        $variables[$name] = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    return $variables;
}

function cssBlock(string $css, string $selector): string
{
    $start = strpos($css, $selector.' {');

    expect($start)->not->toBeFalse("selector {$selector} not found");

    return substr($css, $start, strpos($css, '}', $start) - $start);
}

function proposalCss(): string
{
    $proposal = file_get_contents(dirname(__DIR__, 2).'/openspec/changes/marcos-os-web/visual/marcos-os-design-proposal.md');

    preg_match('/#### Tailwind v4 \+ shadcn \(CSS\)\s*```css(.*?)```/s', $proposal, $match);

    expect($match)->not->toBeEmpty();

    return $match[1];
}

function appCss(): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
}

test('the app stylesheet carries every proposal token with the same value', function (string $selector) {
    $expected = cssVariables(cssBlock(proposalCss(), $selector));
    $actual = cssVariables(cssBlock(appCss(), $selector));

    expect($expected)->not->toBeEmpty();

    foreach ($expected as $name => $value) {
        expect($actual)->toHaveKey($name)
            ->and($actual[$name])->toBe($value, "--{$name} in {$selector}");
    }
})->with([':root', '.dark']);

test('radii, easings and fonts follow the proposal', function () {
    $css = appCss();

    foreach ([
        '--radius-xs: 2px',
        '--radius-sm: 6px',
        '--radius-md: 10px',
        '--radius-lg: 16px',
        '--radius-xl: 24px',
        '--ease-paint: cubic-bezier(0.65, 0, 0.35, 1)',
        '--ease-out-soft: cubic-bezier(0.22, 1, 0.36, 1)',
        "--font-sans: 'Overpass'",
        "--font-display: 'Zilla Slab'",
    ] as $declaration) {
        expect($css)->toContain($declaration);
    }

    $vite = file_get_contents(dirname(__DIR__, 2).'/vite.config.ts');

    expect($vite)->toContain("google('Overpass'")
        ->toContain('weights: [400, 600, 700]')
        ->toContain("google('Zilla Slab'")
        ->toContain('weights: [500, 600]')
        ->toContain("'latin-ext'")
        ->toContain("display: 'swap'");
});
