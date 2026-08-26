<?php

namespace Killi\Core;

/**
 * Manages which dataset currently backs Search — the "where does Killi's
 * data come from right now" concern. This is the local-file slice of a
 * broader Data Source Engine concept that also includes:
 *  - SchemaDetector + api/import.php: bringing one-off rows (CSV/JSON)
 *    into whichever dataset is active
 *  - (future) real database / API connections, once credentials have a
 *    proper config screen instead of being typed anywhere
 *
 * All three are "data source" concerns; this class only handles
 * choosing between pre-configured local JSON datasets.
 */
class DataSourceEngine
{
    /** @var array<int, array<string, mixed>> */
    private array $sources;
    private ?string $activeId;
    private array $faq;

    public function __construct(array $config)
    {
        $this->sources = $config['sources'] ?? [];
        $this->activeId = $config['active'] ?? ($this->sources[0]['id'] ?? null);
        $this->faq = $config['faq'] ?? ['mode' => 'untied'];
    }

    public function all(): array
    {
        return $this->sources;
    }

    public function activeId(): ?string
    {
        return $this->activeId;
    }

    public function active(): ?array
    {
        $found = $this->find($this->activeId ?? '');

        return $found ?? ($this->sources[0] ?? null);
    }

    public function find(string $id): ?array
    {
        foreach ($this->sources as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Where the Memory pillar's FAQ table lives: "untied" (default) is a
     * dedicated local store independent of whatever backs Search/CRUD;
     * "tied" reads/writes a table of its own via the *same* connection as
     * the active source, so a single database serves every pillar without
     * a separate FAQ credential to maintain. See killi_faq_storage().
     */
    public function faqConfig(): array
    {
        return $this->faq;
    }

    /**
     * Which result-detail layout a source's records render through when
     * expanded — "simple" (default, today's compact card only),
     * "business_profile"/"menu_catalog" for the 2 fixed richer views, or
     * "custom" for an admin-composed one built from customSlotsFor()'s
     * palette. Always one of a fixed set of admin-picked options, never
     * admin-authored markup — see KILLI_BUILD_STATUS.md for why.
     */
    public function layoutFor(string $sourceId): string
    {
        $source = $this->find($sourceId);

        return $source['layout'] ?? 'simple';
    }

    /**
     * For layout "custom" only: which slots (from a fixed palette — see
     * killi_set_source_layout()'s validation) an admin picked, and in
     * what order to render them. Recomposes the same renderer functions
     * the 2 fixed rich layouts already use — no new rendering code per
     * admin, no markup, just picking which existing pieces show up.
     */
    public function customSlotsFor(string $sourceId): array
    {
        $source = $this->find($sourceId);

        return $source['custom_slots'] ?? [];
    }
}
