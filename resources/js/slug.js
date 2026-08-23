/**
 * Turns a display name into a URL slug, or gives back '' when it cannot.
 *
 * Deliberately only handles what ASCII can express. Names here are typed in
 * Arabic, and the old one-liner (`name.trim().replace(/\s+/g, '-')`) simply
 * kept them: "عصير برتقال" became the slug "عصير-برتقال", which validated,
 * saved, and turned every link to that product into a percent-encoded URL
 * nobody can read, retype from a printed menu, or paste into a message.
 *
 * Transliterating Arabic here would mean shipping a second copy of the
 * table Laravel already has (Str::slug), and two copies drift. So a
 * non-Latin name yields '' and the server fills the slug in on save — see
 * App\Http\Requests\Admin\Concerns\GeneratesSlug.
 */
export function slugify(name) {
    return (name ?? '')
        .normalize('NFD')
        // Strip the combining marks NFD just split off, so "Café" slugs as
        // "cafe" rather than losing the letter entirely below.
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}
