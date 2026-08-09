<?php

namespace Killi\Core;

/**
 * Validates a license key against a simple local list (data/licenses.json)
 * and reports which package it unlocks. Deliberately simple, per the
 * product's whole zero-DB philosophy — no remote activation server, no
 * network call. A real vendor could later swap this for a signed-key or
 * remote-checked scheme without changing anything else that calls it.
 */
class LicenseManager
{
    private array $licenses;

    public function __construct(array $licenses)
    {
        $this->licenses = $licenses;
    }

    /** @return array{key:string,package:string,status:string}|null */
    public function find(string $key): ?array
    {
        $key = trim($key);
        foreach ($this->licenses as $license) {
            if (hash_equals($license['key'], $key)) {
                return $license;
            }
        }

        return null;
    }

    /** @return array{success:bool,package:?string,message:string} */
    public function activate(string $key): array
    {
        $license = $this->find($key);

        if ($license === null) {
            return ['success' => false, 'package' => null, 'message' => 'That license key was not recognized.'];
        }

        if (($license['status'] ?? 'active') !== 'active') {
            return ['success' => false, 'package' => null, 'message' => 'That license key is no longer active.'];
        }

        return ['success' => true, 'package' => $license['package'], 'message' => 'License activated.'];
    }
}
