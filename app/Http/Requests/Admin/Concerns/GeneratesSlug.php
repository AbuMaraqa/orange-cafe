<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Services\LanguageRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills in a URL-safe slug when the form did not supply a usable one.
 *
 * Names here are typed in Arabic, and the slug box used to be filled from
 * the name verbatim on the client: "عصير برتقال" became the slug
 * "عصير-برتقال". Nothing rejected it — `alpha_dash` is Unicode-aware unless
 * told otherwise, so it validated and saved — and every link to that
 * product then became a percent-encoded URL that nobody can read, retype
 * from a printed menu, or paste into a message.
 *
 * Str::slug() transliterates instead ("عصير برتقال" → "aasyr-brtkal"). Not
 * elegant English, but ASCII, stable, and still just a starting point: the
 * field is left on the form, and anything the admin types by hand wins.
 */
trait GeneratesSlug
{
    /**
     * @param  string  $table  the table whose slug column must stay unique
     * @param  int|null  $ignoreId  the row being updated, excluded from that check
     */
    protected function fillSlug(string $table, ?int $ignoreId = null): void
    {
        $typed = trim((string) $this->input('slug', ''));

        if ($typed !== '') {
            // Something was typed, so normalise it — a slug pasted in Arabic
            // still becomes ASCII — but do not invent a different one. A
            // clash with an existing product is the admin's to see and
            // resolve, not something to paper over with a numeric suffix
            // they never asked for.
            $this->merge(['slug' => Str::slug($typed)]);

            return;
        }

        $generated = Str::slug($this->nameForSlug());

        if ($generated === '') {
            // Nothing to work from. Leaving the slug empty lets the
            // 'required' rule report it in the ordinary way rather than
            // failing here with something the admin cannot act on.
            return;
        }

        $this->merge(['slug' => $this->uniqueSlug($generated, $table, $ignoreId)]);
    }

    /**
     * The name the slug is derived from: the default locale's, falling back
     * to whichever locale was actually filled in — a product named only in
     * English still deserves a slug.
     */
    protected function nameForSlug(): string
    {
        $translations = (array) $this->input('translations', []);
        $default = app(LanguageRegistry::class)->default()?->code;

        if (! empty($translations[$default]['name'])) {
            return (string) $translations[$default]['name'];
        }

        foreach ($translations as $translation) {
            if (! empty($translation['name'])) {
                return (string) $translation['name'];
            }
        }

        return '';
    }

    /**
     * Two products called "قهوة" transliterate to the same slug, and a
     * generated value the admin never saw should not be the thing that
     * fails their save.
     */
    protected function uniqueSlug(string $slug, string $table, ?int $ignoreId): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (
            DB::table($table)
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }
}
