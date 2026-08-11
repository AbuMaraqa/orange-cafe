<?php

namespace App\Http\Middleware;

use App\Services\LanguageRegistry;
use App\Settings\RestaurantSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $languages = app(LanguageRegistry::class);
        $branding = app(RestaurantSettings::class);
        $current = app()->getLocale();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email', 'locale']),
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            'locale' => [
                'current' => $current,
                'direction' => $languages->direction($current),
                'default' => $languages->default()?->code,
                'available' => $languages->active()->map(fn ($language) => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                    'direction' => $language->direction,
                    'url' => LaravelLocalization::getLocalizedURL($language->code, null, [], true),
                ])->values(),
            ],
            'branding' => [
                'name' => $branding->name,
                'tagline' => $branding->tagline,
                'logo_url' => $branding->logo_path ? Storage::disk('public')->url($branding->logo_path) : null,
                'cover_image_url' => $branding->cover_image_path ? Storage::disk('public')->url($branding->cover_image_path) : null,
                'primary_color' => $branding->primary_color,
                'secondary_color' => $branding->secondary_color,
                'font_family' => $branding->font_family,
                'currency_symbol' => $branding->currency_symbol,
                'phone' => $branding->phone,
                'whatsapp' => $branding->whatsapp,
                'instagram' => $branding->instagram,
                'facebook' => $branding->facebook,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
