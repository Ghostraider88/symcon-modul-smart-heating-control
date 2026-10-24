<?php

declare(strict_types=1);

use ModuleVisu\Capability;
use ModuleVisu\Diagnostic;
use ModuleVisu\State;
use ModuleVisu\Style;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../libs/VisuState.php';
require_once __DIR__ . '/../libs/VisuDiagnostic.php';
require_once __DIR__ . '/../libs/VisuCapability.php';
require_once __DIR__ . '/../libs/VisuStyle.php';

final class VisuTemplateTest extends TestCase
{
    public function testStateNormalization(): void
    {
        $this->assertSame('warning', State::normalize('warning'));
        $this->assertSame('unknown', State::normalize('unrecognized'));
        $this->assertSame('normal', State::cssState('ok'));
        $this->assertSame('warning', State::cssState('stale'));
        $this->assertTrue(State::isProblem('offline'));
        $this->assertTrue(State::isProblem('unrecognized'));
        $this->assertFalse(State::isAvailable('not_configured'));
        $this->assertFalse(State::isAvailable('unrecognized'));
    }

    public function testDiagnosticCheckAndRendering(): void
    {
        $checks = [
            Diagnostic::check('ok', 'Verbindung', true, 'Verbindung vorhanden.'),
            Diagnostic::check('bad', 'Token', false, 'Token fehlt.', 'Token konfigurieren.'),
        ];

        $this->assertTrue(Diagnostic::hasProblems($checks));
        $this->assertStringContainsString('Verbindung', Diagnostic::renderList($checks, 'No checks.'));
        $this->assertStringContainsString('Token konfigurieren.', Diagnostic::renderList($checks, 'No checks.'));
        $this->assertStringContainsString('Nothing to inspect.', Diagnostic::renderList([], 'Nothing to inspect.'));
        $this->assertStringNotContainsString('Keine Prüfungen definiert', Diagnostic::renderList([], 'Nothing to inspect.'));
        $this->assertStringContainsString('&lt;script&gt;', Diagnostic::renderList([
            Diagnostic::check('xss', '<script>', false, '<script>')
        ], 'No checks.'));
    }

    public function testCapabilityRendering(): void
    {
        $this->assertTrue(Capability::has(['switch'], 'switch'));
        $this->assertSame('', Capability::when([], 'switch', static fn (): string => 'hidden'));
        $this->assertSame('visible', Capability::when(['switch'], 'switch', static fn (): string => 'visible'));
    }

    public function testButtonArgumentsRemainInsideTheirHtmlAttribute(): void
    {
        $button = Style::button('Switch on', 'Switch', true);

        $this->assertStringContainsString('onclick="requestAction(&quot;Switch&quot;, true)"', $button);
        $this->assertStringNotContainsString('onclick="requestAction("', $button);

        $maliciousButton = Style::button('Click', 'Switch");alert(1);//', '</script><img src=x>');
        $this->assertStringNotContainsString('onclick="requestAction("', $maliciousButton);
        $this->assertStringNotContainsString('</script>', $maliciousButton);
        $this->assertStringContainsString('\\u003C', $maliciousButton);
    }

    public function testTileAlwaysHasAnUpdatableFooterAndAllowsUnknownState(): void
    {
        $tile = Style::renderTile([
            'state'   => 'unrecognized',
            'content' => '',
            'footer'  => '',
        ]);

        $this->assertStringContainsString('mvs-status-unknown', $tile);
        $this->assertStringContainsString('data-mvs-footer', $tile);
        $this->assertStringContainsString('content.innerHTML = message.content', $tile);
    }
}
