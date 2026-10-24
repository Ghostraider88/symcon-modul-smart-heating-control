<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VisuStyle.php';
require_once __DIR__ . '/../libs/VisuState.php';
require_once __DIR__ . '/../libs/VisuDiagnostic.php';
require_once __DIR__ . '/../libs/VisuCapability.php';

use ModuleVisu\Capability;
use ModuleVisu\Diagnostic;
use ModuleVisu\Style;

class MyModule extends IPSModuleStrict
{
    private const MAX_INTERVAL_SECONDS = 86400;

    public function Create(): void
    {
        parent::Create();
        $this->SetVisualizationType(1);

        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyInteger('Interval', 0);
        $this->RegisterAttributeInteger('LastUpdate', 0);

        $this->RegisterTimer('UpdateTimer', 0, 'MYM_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->RegisterVariableString(
            'Status',
            $this->Translate('Status'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            10
        )) {
            $this->SetValue('Status', '');
        }

        if ($this->RegisterVariableBoolean(
            'Switch',
            $this->Translate('Switch'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH],
            20
        )) {
            $this->SetValue('Switch', false);
        }

        $configurationError = $this->GetConfigurationError();
        $configurationValid = $configurationError === '';
        $this->MaintainAction('Switch', $configurationValid);

        $interval = $this->ReadPropertyInteger('Interval');
        $this->SetTimerInterval(
            'UpdateTimer',
            $configurationValid && $interval > 0 ? $interval * 1000 : 0
        );
        $this->SetStatus($this->GetConfigurationStatus($configurationError));

        $this->RegisterMessage($this->GetIDForIdent('Switch'), VM_UPDATE);
    }

    public function MessageSink(int $timestamp, int $senderID, int $message, array $data): void
    {
        if ($message === VM_UPDATE && $senderID === $this->GetIDForIdent('Switch')) {
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());
        }
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'Switch':
                if (!is_bool($value)) {
                    throw new InvalidArgumentException('Switch expects a boolean value.');
                }
                if ($this->GetConfigurationError() !== '') {
                    throw new LogicException('A valid configuration is required to use the switch.');
                }

                $this->SetValue('Switch', $value);
                break;

            case 'RunSelfTest':
                $this->UpdateVisualizationValue($this->GetVisualizationPayload());
                break;

            default:
                throw new InvalidArgumentException('Invalid Ident: ' . $ident);
        }
    }

    public function GetVisualizationTile(): string
    {
        return $this->RenderVisualization();
    }

    public function Update(): void
    {
        $configurationError = $this->GetConfigurationError();
        if ($configurationError !== '') {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus($this->GetConfigurationStatus($configurationError));
            $this->UpdateVisualizationValue($this->GetVisualizationPayload());

            return;
        }

        $timestamp = $this->getTime();
        $this->WriteAttributeInteger('LastUpdate', $timestamp);
        $result = sprintf($this->Translate('Demo update completed at %s'), date('H:i:s', $timestamp));

        $this->SetValue('Status', $result);
        $this->SetStatus(102);
        $this->UpdateVisualizationValue($this->GetVisualizationPayload());
    }

    public function HelloWorld(): string
    {
        return $this->Translate('Hello World');
    }

    protected function getTime(): int
    {
        return time();
    }

    private function GetVisualizationPayload(): array
    {
        $isOn = (bool) $this->GetValue('Switch');
        $configurationError = $this->GetConfigurationError();
        $state = $this->GetConfigurationState($configurationError, $isOn);
        $checks = $this->GetDiagnosticChecks($configurationError);

        return [
            'state'       => $state,
            'stateLabel'  => $this->Translate($this->GetStateLabel($state)),
            'content'     => $this->RenderVisualizationContent($isOn, $checks, $configurationError === ''),
            'footer'      => $this->GetLastUpdateLabel(),
        ];
    }

    private function RenderVisualization(): string
    {
        $isOn = (bool) $this->GetValue('Switch');
        $configurationError = $this->GetConfigurationError();
        $state = $this->GetConfigurationState($configurationError, $isOn);
        $checks = $this->GetDiagnosticChecks($configurationError);

        return Style::renderTile([
            'title'      => $this->Translate('My Module'),
            'icon'       => 'fa-light fa-cube',
            'state'      => $state,
            'stateLabel' => $this->Translate($this->GetStateLabel($state)),
            'content'    => $this->RenderVisualizationContent($isOn, $checks, $configurationError === ''),
            'footer'     => $this->GetLastUpdateLabel(),
        ]);
    }

    private function RenderVisualizationContent(bool $isOn, array $checks, bool $configurationValid): string
    {
        $state = $configurationValid ? ($isOn ? 'active' : 'inactive') : 'unknown';
        $capabilities = $configurationValid ? ['switch'] : [];
        $content = Style::stateBlock(
            $this->Translate('Current state'),
            $configurationValid
                ? $this->Translate($isOn ? 'Switched on' : 'Switched off')
                : $this->Translate('Switch unavailable until configuration is valid.'),
            $state
        );

        $content .= Capability::when(
            $capabilities,
            'switch',
            fn (): string => Style::section(
                $this->Translate('Actions'),
                '<div class="mvs-actions">'
                . Style::button(
                    $this->Translate($isOn ? 'Switch off' : 'Switch on'),
                    'Switch',
                    !$isOn
                )
                . '</div>'
            )
        );

        $content .= Style::section(
            $this->Translate('Diagnostics'),
            Style::button($this->Translate('Run self-test'), 'RunSelfTest', true, true)
            . '<div class="mvs-content" style="margin-top:8px">'
            . Diagnostic::renderList($checks, $this->Translate('No checks defined.'))
            . '</div>'
        );

        return $content;
    }

    private function GetDiagnosticChecks(string $configurationError): array
    {
        return [
            Diagnostic::check(
                'configuration',
                $this->Translate('Configuration'),
                $configurationError === '',
                $configurationError === ''
                    ? $this->Translate('Configuration is valid.')
                    : $this->Translate($configurationError),
                $configurationError === '' ? '' : $this->Translate('Correct the configuration and apply the changes.'),
                $configurationError === '' ? 'ok' : 'error'
            ),
            Diagnostic::check(
                'update',
                $this->Translate('Device communication'),
                false,
                $this->Translate('This example module does not communicate with a device.'),
                $this->Translate('Add and verify a real device request before using this template in production.'),
                'unknown'
            ),
        ];
    }

    private function GetConfigurationError(): string
    {
        $hostname = trim($this->ReadPropertyString('Hostname'));
        if ($hostname === '') {
            return 'Hostname is not configured.';
        }
        if (!$this->IsValidHostname($hostname)) {
            return 'Hostname is invalid.';
        }

        $interval = $this->ReadPropertyInteger('Interval');
        if ($interval < 0 || $interval > self::MAX_INTERVAL_SECONDS) {
            return 'Update interval must be between 0 and 86400 seconds.';
        }

        return '';
    }

    private function IsValidHostname(string $hostname): bool
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/\A(?=.{1,253}\.?\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*\.?\z/',
            $hostname
        ) === 1;
    }

    private function GetConfigurationState(string $configurationError, bool $isOn): string
    {
        if ($configurationError !== '') {
            return $configurationError === 'Hostname is not configured.' ? 'not_configured' : 'error';
        }

        return $isOn ? 'active' : 'inactive';
    }

    private function GetConfigurationStatus(string $configurationError): int
    {
        if ($configurationError === '') {
            return 102;
        }

        return $configurationError === 'Hostname is not configured.' ? 104 : 202;
    }

    private function GetStateLabel(string $state): string
    {
        return match ($state) {
            'active'         => 'Active',
            'inactive'       => 'Inactive',
            'not_configured' => 'Not configured yet',
            default          => 'Configuration error',
        };
    }

    private function GetLastUpdateLabel(): string
    {
        $lastUpdate = $this->ReadAttributeInteger('LastUpdate');

        return $lastUpdate === 0
            ? $this->Translate('Not updated yet.')
            : sprintf($this->Translate('Last demo update: %s'), date('d.m.Y H:i:s', $lastUpdate));
    }
}
