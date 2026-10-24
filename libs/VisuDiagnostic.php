<?php

declare(strict_types=1);

namespace ModuleVisu;

final class Diagnostic
{
    public static function check(
        string $key,
        string $label,
        bool $passed,
        string $message,
        string $recommendation = '',
        string $severity = 'error'
    ): array {
        $severity = State::normalize($passed ? 'ok' : $severity);

        return [
            'key'             => $key,
            'label'           => $label,
            'passed'          => $passed,
            'message'         => $message,
            'recommendation'  => $recommendation,
            'severity'        => $severity,
        ];
    }

    public static function renderList(array $checks, string $emptyLabel): string
    {
        $html = '';

        foreach ($checks as $check) {
            $passed = (bool) ($check['passed'] ?? false);
            $state = State::cssState((string) ($check['severity'] ?? ($passed ? 'ok' : 'error')));
            $label = self::escape((string) ($check['label'] ?? ''));
            $message = self::escape((string) ($check['message'] ?? ''));
            $recommendation = self::escape((string) ($check['recommendation'] ?? ''));
            $icon = $passed ? '✓' : '!';
            $recommendationMarkup = $recommendation === ''
                ? ''
                : '<br><small>→ ' . $recommendation . '</small>';

            $html .= '<div class="mvs-alert mvs-status-' . $state . '">'
                . '<strong>' . $icon . ' ' . $label . '</strong>'
                . '<br>' . $message
                . $recommendationMarkup
                . '</div>';
        }

        return $html === ''
            ? '<div class="mvs-alert mvs-status-info">' . self::escape($emptyLabel) . '</div>'
            : $html;
    }

    public static function hasProblems(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!(bool) ($check['passed'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
