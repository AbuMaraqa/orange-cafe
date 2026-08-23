<?php

namespace App\Observers;

use App\Models\Language;
use App\Services\LanguageRegistry;

class LanguageObserver
{
    public function __construct(protected LanguageRegistry $registry) {}

    public function saving(Language $language): void
    {
        // A default language is meaningless if it's inactive.
        if ($language->is_default) {
            $language->is_active = true;
        }
    }

    public function saved(Language $language): void
    {
        if ($language->is_default) {
            // Query builder update — bypasses model events, so this can't recurse.
            Language::query()
                ->whereKeyNot($language->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $this->registry->flush();
        $this->registry->syncRuntimeConfig();
    }

    public function deleted(Language $language): void
    {
        $this->registry->flush();
        $this->registry->syncRuntimeConfig();
    }
}
