<?php

namespace Killi\Core;

/**
 * Feature access is license/package based, not arbitrary per-feature
 * passwords: a package (e.g. "Basic"/"Pro"/"Enterprise") bundles a fixed
 * set of features, and a user gets everything their package includes.
 * This class only answers "does package X include feature Y" — see
 * killi_current_package() in bootstrap.php for how the package itself is
 * resolved (from a host app's injected identity, or a configured default
 * when running standalone).
 */
class EntitlementManager
{
    /** @var array<string, string[]> package id => feature list */
    private array $packages;
    private string $defaultPackage;

    public function __construct(array $config)
    {
        $this->packages = [];
        foreach ($config['packages'] ?? [] as $package) {
            $this->packages[$package['id']] = $package['features'] ?? [];
        }
        $this->defaultPackage = $config['default_package'] ?? array_key_first($this->packages) ?? '';
    }

    public function defaultPackage(): string
    {
        return $this->defaultPackage;
    }

    public function packageIds(): array
    {
        return array_keys($this->packages);
    }

    public function features(string $packageId): array
    {
        return $this->packages[$packageId] ?? [];
    }

    public function hasFeature(string $packageId, string $feature): bool
    {
        return in_array($feature, $this->features($packageId), true);
    }
}
